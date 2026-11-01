<?php

declare(strict_types=1);

namespace Kuman;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

class UploadedFile implements UploadedFileInterface
{
    private bool $moved = false;

    public function __construct(
        private readonly string $path,
        private readonly ?int $size,
        private readonly int $error = UPLOAD_ERR_OK,
        private readonly ?string $clientFilename = null,
        private readonly ?string $clientMediaType = null,
    ) {}

    /**
     * @param array{tmp_name?: string, size?: int, error?: int, name?: string, type?: string} $file An entry of Request::file().
     */
    public static function fromArray(array $file): static
    {
        return new static(
            (string)($file['tmp_name'] ?? ''),
            isset($file['size']) ? (int)$file['size'] : null,
            (int)($file['error'] ?? UPLOAD_ERR_OK),
            $file['name'] ?? null,
            ($file['type'] ?? '') === '' ? null : $file['type'],
        );
    }

    public function getStream(): StreamInterface
    {
        $this->assertUsable();
        return Stream::open($this->path);
    }

    public function moveTo(string $targetPath): void
    {
        $this->assertUsable();
        if ($targetPath === '') {
            throw new InvalidArgumentException('The target path is empty.');
        }

        // The temporary file belongs to the request, so a move is a rename when it can be.
        if (!@rename($this->path, $targetPath)) {
            if (!@copy($this->path, $targetPath)) {
                throw new RuntimeException("Cannot move the uploaded file to $targetPath");
            }
            @unlink($this->path);
        }
        $this->moved = true;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }

    private function assertUsable(): void
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('The upload failed, error code ' . $this->error);
        }
        if ($this->moved) {
            throw new RuntimeException('The uploaded file was already moved.');
        }
    }
}
