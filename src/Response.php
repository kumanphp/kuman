<?php

declare(strict_types=1);

namespace Kuman;

use Stringable;
use Workerman\Protocols\Http\Response as BaseResponse;

class Response extends BaseResponse
{
    /**
     * Strings become HTML, anything else that is not a response becomes JSON.
     */
    public static function from(mixed $value): BaseResponse
    {
        return match (true) {
            $value instanceof BaseResponse => $value,
            is_string($value), $value instanceof Stringable => static::html((string)$value),
            default => static::json($value),
        };
    }

    public static function json(mixed $data, int $status = 200): static
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return new static($status, ['Content-Type' => 'application/json'], $body);
    }

    public static function html(string $body, int $status = 200): static
    {
        return new static($status, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    public static function text(string $body, int $status = 200): static
    {
        return new static($status, ['Content-Type' => 'text/plain; charset=utf-8'], $body);
    }

    public static function redirect(string $location, int $status = 302): static
    {
        return new static($status, ['Location' => $location]);
    }

    /**
     * Pass the request to honour If-Modified-Since and Range.
     */
    public static function file(string $path, ?Request $request = null): static
    {
        $response = new static();
        if ($request === null) {
            return $response->withFile($path);
        }

        $modified = gmdate('D, d M Y H:i:s', (int)filemtime($path)) . ' GMT';
        if ($request->header('if-modified-since') === $modified) {
            return $response->withStatus(304);
        }

        $size = (int)filesize($path);
        $range = self::range($request->header('range'), $size);
        if ($range === false) {
            return new static(416, ['Content-Range' => "bytes */$size", 'Accept-Ranges' => 'bytes']);
        }
        return $response->withFile($path, ...($range ?? []));
    }

    /**
     * @return array{0: int, 1: int}|false|null Offset and length, false when unsatisfiable, null to send it all.
     */
    private static function range(?string $header, int $size): array|false|null
    {
        if ($header === null || $size <= 0 || !preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $match)) {
            return null;
        }
        [, $start, $end] = $match;
        if ($start === '' && $end === '') {
            return false;
        }
        if ($start === '') {
            $offset = max(0, $size - (int)$end);
            return (int)$end > 0 ? [$offset, $size - $offset] : false;
        }

        $offset = (int)$start;
        $last = $end === '' ? $size - 1 : min((int)$end, $size - 1);
        return $offset < $size && $last >= $offset ? [$offset, $last - $offset + 1] : false;
    }
}
