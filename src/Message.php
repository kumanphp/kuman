<?php

declare(strict_types=1);

namespace Kuman;

use InvalidArgumentException;

/**
 * The header half of PSR-7 MessageInterface, shared by Request and Response.
 */
trait Message
{
    /** @var ?array<string, array{0: string, 1: array<int, string>}> Lowercase name => [name as given, values]. */
    private ?array $headerMap = null;

    /**
     * Headers to start from when none were set, as name => value or name => values.
     *
     * @return array<string, string|array<int, string>>
     */
    abstract protected function initialHeaders(): array;

    /**
     * Called on the copy after a header changed.
     */
    protected function headersChanged(): void {}

    /**
     * @return array<string, array<int, string>>
     */
    public function getHeaders(): array
    {
        $headers = [];
        foreach ($this->headerMap() as [$name, $values]) {
            $headers[$name] = $values;
        }
        return $headers;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headerMap()[strtolower($name)]);
    }

    /**
     * @return array<int, string>
     */
    public function getHeader(string $name): array
    {
        return $this->headerMap()[strtolower($name)][1] ?? [];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): static
    {
        $map = $this->headerMap();
        $map[strtolower(self::headerName($name))] = [$name, self::headerValues($value)];
        return $this->withHeaderMap($map);
    }

    public function withAddedHeader(string $name, $value): static
    {
        $map = $this->headerMap();
        $key = strtolower(self::headerName($name));
        $values = self::headerValues($value);
        $map[$key] = isset($map[$key]) ? [$map[$key][0], [...$map[$key][1], ...$values]] : [$name, $values];
        return $this->withHeaderMap($map);
    }

    public function withoutHeader(string $name): static
    {
        $map = $this->headerMap();
        unset($map[strtolower($name)]);
        return $this->withHeaderMap($map);
    }

    /**
     * @param array<string, string|array<int, string>> $headers
     */
    private function setHeaders(array $headers): void
    {
        $map = [];
        foreach ($headers as $name => $value) {
            $name = (string)$name;
            $key = strtolower(self::headerName($name));
            $values = self::headerValues($value);
            $map[$key] = isset($map[$key]) ? [$map[$key][0], [...$map[$key][1], ...$values]] : [$name, $values];
        }
        $this->headerMap = $map;
    }

    /**
     * @return array<string, array{0: string, 1: array<int, string>}>
     */
    private function headerMap(): array
    {
        if ($this->headerMap === null) {
            $this->setHeaders($this->initialHeaders());
        }
        return $this->headerMap;
    }

    /**
     * @param array<string, array{0: string, 1: array<int, string>}> $map
     */
    private function withHeaderMap(array $map): static
    {
        $clone = $this->copy();
        $clone->headerMap = $map;
        $clone->headersChanged();
        return $clone;
    }

    protected function copy(): static
    {
        return clone $this;
    }

    private static function headerName(string $name): string
    {
        if (!preg_match("/^[a-zA-Z0-9'`#$%&*+.^_|~!-]+$/", $name)) {
            throw new InvalidArgumentException("Invalid header name: $name");
        }
        return $name;
    }

    /**
     * @return array<int, string>
     */
    private static function headerValues(mixed $value): array
    {
        $values = is_array($value) ? array_values($value) : [$value];
        if ($values === []) {
            throw new InvalidArgumentException('A header needs at least one value.');
        }

        return array_map(static function (mixed $item): string {
            if (!is_string($item) && !is_int($item) && !is_float($item)) {
                throw new InvalidArgumentException('A header value must be a string or a list of strings.');
            }
            $item = trim((string)$item, " \t");
            if (strpbrk($item, "\r\n\0") !== false) {
                throw new InvalidArgumentException('A header value cannot contain line breaks.');
            }
            return $item;
        }, $values);
    }
}
