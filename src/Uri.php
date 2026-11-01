<?php

declare(strict_types=1);

namespace Kuman;

use InvalidArgumentException;
use Psr\Http\Message\UriInterface;

class Uri implements UriInterface
{
    private const PORTS = ['http' => 80, 'https' => 443];
    private const UNRESERVED = 'a-zA-Z0-9_\-\.~!\$&\'\(\)\*\+,;=%:@\/';

    private string $scheme = '';
    private string $userInfo = '';
    private string $host = '';
    private ?int $port = null;
    private string $path = '';
    private string $query = '';
    private string $fragment = '';

    public function __construct(string $uri = '')
    {
        if ($uri === '') {
            return;
        }
        $parts = parse_url($uri);
        if ($parts === false) {
            throw new InvalidArgumentException("Unable to parse URI: $uri");
        }

        $this->scheme = strtolower($parts['scheme'] ?? '');
        $this->userInfo = isset($parts['user'])
            ? $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '')
            : '';
        $this->host = strtolower($parts['host'] ?? '');
        $this->port = self::port($parts['port'] ?? null, $this->scheme);
        $this->path = self::encode($parts['path'] ?? '', false);
        $this->query = self::encode($parts['query'] ?? '', true);
        $this->fragment = self::encode($parts['fragment'] ?? '', true);
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getAuthority(): string
    {
        if ($this->host === '') {
            return '';
        }
        return ($this->userInfo === '' ? '' : "$this->userInfo@") . $this->host
            . ($this->port === null ? '' : ":$this->port");
    }

    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getFragment(): string
    {
        return $this->fragment;
    }

    public function withScheme(string $scheme): static
    {
        $clone = clone $this;
        $clone->scheme = strtolower($scheme);
        $clone->port = self::port($this->port, $clone->scheme);
        return $clone;
    }

    public function withUserInfo(string $user, ?string $password = null): static
    {
        $clone = clone $this;
        $clone->userInfo = $user === '' ? '' : $user . ($password === null || $password === '' ? '' : ":$password");
        return $clone;
    }

    public function withHost(string $host): static
    {
        $clone = clone $this;
        $clone->host = strtolower($host);
        return $clone;
    }

    public function withPort(?int $port): static
    {
        $clone = clone $this;
        $clone->port = self::port($port, $this->scheme);
        return $clone;
    }

    public function withPath(string $path): static
    {
        $clone = clone $this;
        $clone->path = self::encode($path, false);
        return $clone;
    }

    public function withQuery(string $query): static
    {
        $clone = clone $this;
        $clone->query = self::encode(ltrim($query, '?'), true);
        return $clone;
    }

    public function withFragment(string $fragment): static
    {
        $clone = clone $this;
        $clone->fragment = self::encode(ltrim($fragment, '#'), true);
        return $clone;
    }

    public function __toString(): string
    {
        $authority = $this->getAuthority();
        $path = $this->path;
        if ($authority !== '' && $path !== '' && $path[0] !== '/') {
            $path = "/$path";
        } elseif ($authority === '' && str_starts_with($path, '//')) {
            $path = '/' . ltrim($path, '/');
        }

        return ($this->scheme === '' ? '' : "$this->scheme:")
            . ($authority === '' && $this->scheme !== 'file' ? '' : "//$authority")
            . $path
            . ($this->query === '' ? '' : "?$this->query")
            . ($this->fragment === '' ? '' : "#$this->fragment");
    }

    /**
     * The default port of the scheme counts as no port.
     */
    private static function port(?int $port, string $scheme): ?int
    {
        if ($port === null) {
            return null;
        }
        if ($port < 0 || $port > 65535) {
            throw new InvalidArgumentException("Invalid port: $port");
        }
        return (self::PORTS[$scheme] ?? null) === $port ? null : $port;
    }

    /**
     * Percent-encode what the URI syntax does not allow, leaving existing escapes alone.
     */
    private static function encode(string $value, bool $query): string
    {
        $allowed = self::UNRESERVED . ($query ? '\?' : '');
        return preg_replace_callback(
            "/(?:[^$allowed]++|%(?![A-Fa-f0-9]{2}))/",
            static fn(array $match) => rawurlencode($match[0]),
            $value
        );
    }
}
