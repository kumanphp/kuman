<?php

namespace Fixture;

use Kuman\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * A PSR-7 response from "another library": it is not a Kuman\Response and only implements the interface.
 */
class ForeignResponse implements ResponseInterface
{
    public function __construct(private Response $inner) {}

    public function getProtocolVersion(): string { return $this->inner->getProtocolVersion(); }
    public function withProtocolVersion(string $version): static { return new static($this->inner->withProtocolVersion($version)); }
    public function getHeaders(): array { return $this->inner->getHeaders(); }
    public function hasHeader(string $name): bool { return $this->inner->hasHeader($name); }
    public function getHeader(string $name): array { return $this->inner->getHeader($name); }
    public function getHeaderLine(string $name): string { return $this->inner->getHeaderLine($name); }
    public function withHeader(string $name, $value): static { return new static($this->inner->withHeader($name, $value)); }
    public function withAddedHeader(string $name, $value): static { return new static($this->inner->withAddedHeader($name, $value)); }
    public function withoutHeader(string $name): static { return new static($this->inner->withoutHeader($name)); }
    public function getBody(): StreamInterface { return $this->inner->getBody(); }
    public function withBody(StreamInterface $body): static { return new static($this->inner->withBody($body)); }
    public function getStatusCode(): int { return $this->inner->getStatusCode(); }
    public function withStatus(int $code, string $reasonPhrase = ''): static { return new static($this->inner->withStatus($code, $reasonPhrase)); }
    public function getReasonPhrase(): string { return $this->inner->getReasonPhrase(); }
}
