<?php

declare(strict_types=1);

namespace Kuman;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

class Stream implements StreamInterface
{
    /** @var resource|null */
    private $resource;

    /**
     * @param resource $resource
     */
    public function __construct($resource)
    {
        if (!is_resource($resource)) {
            throw new InvalidArgumentException('Stream needs a resource.');
        }
        $this->resource = $resource;
    }

    public static function from(string $content = ''): static
    {
        $resource = fopen('php://temp', 'r+b');
        fwrite($resource, $content);
        rewind($resource);
        return new static($resource);
    }

    public static function open(string $path, string $mode = 'rb'): static
    {
        $resource = @fopen($path, $mode);
        if ($resource === false) {
            throw new RuntimeException("Cannot open $path");
        }
        return new static($resource);
    }

    public function __toString(): string
    {
        try {
            if ($this->isSeekable()) {
                $this->rewind();
            }
            return $this->getContents();
        } catch (Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        if ($this->resource !== null) {
            fclose($this->resource);
            $this->resource = null;
        }
    }

    public function detach()
    {
        $resource = $this->resource;
        $this->resource = null;
        return $resource;
    }

    public function getSize(): ?int
    {
        if ($this->resource === null) {
            return null;
        }
        $size = fstat($this->resource)['size'] ?? null;
        return $size === null ? null : (int)$size;
    }

    public function tell(): int
    {
        $position = $this->resource === null ? false : ftell($this->resource);
        if ($position === false) {
            throw new RuntimeException('Cannot tell the position of the stream.');
        }
        return $position;
    }

    public function eof(): bool
    {
        return $this->resource === null || feof($this->resource);
    }

    public function isSeekable(): bool
    {
        return $this->resource !== null && (bool)$this->getMetadata('seekable');
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if (!$this->isSeekable() || fseek($this->resource, $offset, $whence) === -1) {
            throw new RuntimeException('Cannot seek in the stream.');
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return $this->resource !== null && strpbrk((string)$this->getMetadata('mode'), 'xwca+') !== false;
    }

    public function write(string $string): int
    {
        if (!$this->isWritable()) {
            throw new RuntimeException('The stream is not writable.');
        }
        $written = fwrite($this->resource, $string);
        if ($written === false) {
            throw new RuntimeException('Cannot write to the stream.');
        }
        return $written;
    }

    public function isReadable(): bool
    {
        return $this->resource !== null && strpbrk((string)$this->getMetadata('mode'), 'r+') !== false;
    }

    public function read(int $length): string
    {
        if (!$this->isReadable()) {
            throw new RuntimeException('The stream is not readable.');
        }
        if ($length === 0) {
            return '';
        }
        $data = fread($this->resource, $length);
        if ($data === false) {
            throw new RuntimeException('Cannot read from the stream.');
        }
        return $data;
    }

    public function getContents(): string
    {
        if (!$this->isReadable()) {
            throw new RuntimeException('The stream is not readable.');
        }
        $contents = stream_get_contents($this->resource);
        if ($contents === false) {
            throw new RuntimeException('Cannot read from the stream.');
        }
        return $contents;
    }

    public function getMetadata(?string $key = null)
    {
        if ($this->resource === null) {
            return $key === null ? [] : null;
        }
        $metadata = stream_get_meta_data($this->resource);
        return $key === null ? $metadata : ($metadata[$key] ?? null);
    }

    public function __destruct()
    {
        $this->close();
    }
}
