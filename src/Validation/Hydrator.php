<?php

declare(strict_types=1);

namespace Kuman\Validation;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

/**
 * Fills Dto objects. The reflection work happens once per class and worker: the cache
 * holds class descriptions only, never anything from a request.
 */
final class Hydrator
{
    /** @var array<string, array<string, Field>> */
    private static array $fields = [];

    /** @var array<string, ReflectionClass> */
    private static array $classes = [];

    /**
     * @return array<string, Field>
     */
    public static function fields(string $class): array
    {
        if (isset(self::$fields[$class])) {
            return self::$fields[$class];
        }

        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isEnum()) {
            throw new InvalidArgumentException("$class cannot be created from a request.");
        }

        // A class may hold itself, so it has to be known before its fields are read.
        self::$classes[$class] = $reflection;
        self::$fields[$class] = [];
        try {
            $fields = [];
            foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                if (!$property->isStatic()) {
                    $fields[$property->getName()] = Field::fromProperty($property);
                }
            }
        } catch (Throwable $e) {
            unset(self::$fields[$class], self::$classes[$class]);
            throw $e;
        }
        return self::$fields[$class] = $fields;
    }

    /**
     * @param array<string, mixed> $data Keys that are not fields are ignored.
     * @param array<int, array{field: string, rule: string, message: string}> $errors
     * @return object Only complete when this added nothing to $errors.
     */
    public static function make(string $class, array $data, bool $strict, array &$errors, string $prefix = ''): object
    {
        $fields = self::fields($class);
        $object = self::$classes[$class]->newInstanceWithoutConstructor();
        foreach ($fields as $field) {
            $before = count($errors);
            $value = $field->read($data, $strict, $errors, $prefix);
            if (count($errors) === $before) {
                $field->property->setValue($object, $value);
            }
        }
        return $object;
    }
}
