<?php

declare(strict_types=1);

namespace Kuman;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;
use Workerman\Coroutine\Context;
use Workerman\Protocols\Http\Request as BaseRequest;
use Workerman\Protocols\Http\Response as BaseResponse;

/**
 * The Workerman request with the PSR-7 server request interface on top. The with*()
 * methods return copies and keep the Workerman accessors in step: path(), get(),
 * header() and method() see what the copy says. post() and file() keep reading the
 * received body, see withParsedBody() and withUploadedFiles() for PSR-7 replacements.
 */
class Request extends BaseRequest implements ServerRequestInterface
{
    use Message;

    /** @var array<string, string> */
    public array $params = [];

    /** @var array<string, mixed> */
    private array $attributes = [];

    private ?UriInterface $uriObject = null;

    private ?StreamInterface $bodyStream = null;

    private bool $parsedBodySet = false;

    private array|object|null $parsedBody = null;

    /** @var ?array<string, mixed> */
    private ?array $uploadedFiles = null;

    /** A copy made by with*() does not delete the uploaded files, the request it came from does. */
    private bool $derived = false;

    /**
     * The request being handled by the current fiber.
     */
    public static function current(): ?static
    {
        return Context::get(self::class);
    }

    public function param(string $name, mixed $default = null): mixed
    {
        return $this->params[$name] ?? $default;
    }

    /**
     * @return array<string, mixed> Query and body values, the body wins.
     */
    public function all(): array
    {
        return $this->post() + $this->get();
    }

    public function input(string $name, mixed $default = null): mixed
    {
        return $this->post($name, $this->get($name, $default));
    }

    public function ip(): string
    {
        return $this->connection->getRemoteIp();
    }

    /**
     * Send the response. App calls this with the handler result, and a handler that
     * returned null calls it later, for example from a timer.
     */
    public function respond(mixed $value): void
    {
        $response = Response::from($value)->toWorkerman();
        $raw = false;
        if ($this->method() === 'HEAD') {
            $response = self::head($response);
            $raw = true;
        }

        $keepAlive = $this->header('connection');
        $reusable = $keepAlive === null
            ? $this->protocolVersion() === '1.1'
            : strcasecmp($keepAlive, 'keep-alive') === 0;

        // Later chunks need the connection, whatever the client asked for.
        $chunked = $response instanceof BaseResponse && $response->getHeader('Transfer-Encoding') === 'chunked';

        if ($reusable || $chunked) {
            $this->connection->send($response, $raw);
            return;
        }
        $this->connection->close($response, $raw);
    }

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion();
    }

    public function withProtocolVersion(string $version): static
    {
        $copy = $this->copy();
        $copy->data['protocolVersion'] = $version;
        return $copy;
    }

    public function getRequestTarget(): string
    {
        return $this->uri();
    }

    public function withRequestTarget(string $requestTarget): static
    {
        if ($requestTarget === '' || preg_match('/\s/', $requestTarget)) {
            throw new InvalidArgumentException('Invalid request target.');
        }
        $copy = $this->copy();
        $copy->retarget($requestTarget);
        $copy->uriObject = null;
        return $copy;
    }

    public function getMethod(): string
    {
        return $this->method();
    }

    public function withMethod(string $method): static
    {
        if (!preg_match("/^[a-zA-Z0-9!#$%&'*+.^_`|~-]+$/", $method)) {
            throw new InvalidArgumentException("Invalid method: $method");
        }
        $copy = $this->copy();
        $copy->data['method'] = $method;
        return $copy;
    }

    public function getUri(): UriInterface
    {
        return $this->uriObject ??= $this->buildUri();
    }

    /**
     * The path and query of the copy are what path(), get() and the router see.
     */
    public function withUri(UriInterface $uri, bool $preserveHost = false): static
    {
        $host = $uri->getHost();
        $copy = $this;
        if ($host !== '' && (!$preserveHost || $this->getHeaderLine('Host') === '')) {
            $copy = $this->withHeader('Host', $host . ($uri->getPort() === null ? '' : ':' . $uri->getPort()));
        }
        $copy = $copy === $this ? $this->copy() : $copy;

        $copy->uriObject = $uri;
        $copy->retarget(($uri->getPath() ?: '/') . ($uri->getQuery() === '' ? '' : '?' . $uri->getQuery()));
        return $copy;
    }

    /**
     * @return array<string, mixed>
     */
    public function getServerParams(): array
    {
        $params = [
            'SERVER_PROTOCOL' => 'HTTP/' . $this->protocolVersion(),
            'REQUEST_METHOD' => $this->method(),
            'REQUEST_URI' => $this->uri(),
            'QUERY_STRING' => $this->queryString(),
        ];
        if ($this->connection !== null) {
            $params += [
                'REMOTE_ADDR' => $this->connection->getRemoteIp(),
                'REMOTE_PORT' => $this->connection->getRemotePort(),
                'SERVER_ADDR' => $this->connection->getLocalIp(),
                'SERVER_PORT' => $this->connection->getLocalPort(),
            ];
        }
        return $params;
    }

    /**
     * @return array<string, string>
     */
    public function getCookieParams(): array
    {
        return $this->cookie();
    }

    public function withCookieParams(array $cookies): static
    {
        $copy = $this->copy();
        $copy->data['cookie'] = $cookies;
        return $copy;
    }

    /**
     * @return array<string, mixed>
     */
    public function getQueryParams(): array
    {
        return $this->get();
    }

    public function withQueryParams(array $query): static
    {
        $copy = $this->copy();
        $copy->data['get'] = $query;
        return $copy;
    }

    /**
     * @return array<string, mixed> UploadedFileInterface objects, nested like the field names.
     */
    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles ??= $this->receivedUploads();
    }

    /**
     * @param array<string, mixed> $uploadedFiles
     */
    public function withUploadedFiles(array $uploadedFiles): static
    {
        array_walk_recursive($uploadedFiles, static function (mixed $file): void {
            if (!$file instanceof UploadedFileInterface) {
                throw new InvalidArgumentException('Uploaded files must implement UploadedFileInterface.');
            }
        });
        $copy = $this->copy();
        $copy->uploadedFiles = $uploadedFiles;
        return $copy;
    }

    /**
     * Form and JSON bodies are parsed, any other body is null until a middleware sets it.
     */
    public function getParsedBody(): array|object|null
    {
        if ($this->parsedBodySet) {
            return $this->parsedBody;
        }

        $type = strtolower((string)$this->header('content-type', ''));
        $parsed = str_contains($type, 'json')
            || str_contains($type, 'x-www-form-urlencoded')
            || str_contains($type, 'multipart/form-data');
        return $parsed && $this->rawBody() !== '' ? $this->post() : null;
    }

    /**
     * Only getParsedBody() reports it: post(), input() and all() keep reading the received body.
     */
    public function withParsedBody($data): static
    {
        if ($data !== null && !is_array($data) && !is_object($data)) {
            throw new InvalidArgumentException('The parsed body must be an array, an object or null.');
        }
        $copy = $this->copy();
        $copy->parsedBodySet = true;
        $copy->parsedBody = $data;
        return $copy;
    }

    public function getBody(): StreamInterface
    {
        return $this->bodyStream ??= Stream::from($this->rawBody());
    }

    public function withBody(StreamInterface $body): static
    {
        $copy = $this->copy();
        $copy->bodyStream = $body;
        $copy->buffer = $this->rawHead() . "\r\n\r\n" . $body;
        $copy->parsedBodySet = false;
        $copy->uploadedFiles = null;

        // Parsing the new body creates upload files that only this copy knows about.
        unset($copy->data['post'], $copy->data['files']);
        $copy->derived = false;
        return $copy;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }

    public function withAttribute(string $name, mixed $value): static
    {
        $copy = $this->copy();
        $copy->attributes[$name] = $value;
        return $copy;
    }

    public function withoutAttribute(string $name): static
    {
        $copy = $this->copy();
        unset($copy->attributes[$name]);
        return $copy;
    }

    public function __destruct()
    {
        if (!$this->derived) {
            parent::__destruct();
        }
    }

    protected function copy(): static
    {
        $copy = clone $this;
        $copy->derived = true;
        return $copy;
    }

    protected function initialHeaders(): array
    {
        return $this->header();
    }

    protected function headersChanged(): void
    {
        $headers = [];
        foreach ($this->headerMap() as $key => [, $values]) {
            $headers[$key] = implode(',', $values);
        }
        $this->data['headers'] = $headers;
    }

    private function retarget(string $target): void
    {
        $this->data['uri'] = $target;
        unset($this->data['path'], $this->data['query_string'], $this->data['get']);
    }

    private function buildUri(): UriInterface
    {
        $target = $this->uri();
        $host = (string)$this->host();
        $scheme = $this->connection?->transport === 'ssl' ? 'https' : 'http';

        // A malformed Host header must not make the request unreadable, and "user@" must not get into the authority.
        $usable = preg_match('/^[a-z0-9._~\-\[\]:]+$/i', $host) === 1 && str_starts_with($target, '/');
        try {
            return new Uri($usable ? "$scheme://$host$target" : $target);
        } catch (InvalidArgumentException) {
            return (new Uri())->withPath($this->path())->withQuery($this->queryString());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function receivedUploads(): array
    {
        // file() parses the body again when it finds no files, which is wasted on a form or JSON.
        if (!str_contains(strtolower((string)$this->header('content-type', '')), 'multipart/')) {
            return [];
        }
        return self::wrapUploads((array)$this->file());
    }

    /**
     * @param array<string, mixed> $files
     * @return array<string, mixed>
     */
    private static function wrapUploads(array $files): array
    {
        $wrapped = [];
        foreach ($files as $name => $file) {
            if (!is_array($file)) {
                continue;
            }
            $wrapped[$name] = isset($file['tmp_name']) && !is_array($file['tmp_name'])
                ? UploadedFile::fromArray($file)
                : self::wrapUploads($file);
        }
        return $wrapped;
    }

    private static function head(BaseResponse $response): string
    {
        if (isset($response->file)) {
            $response->header('Content-Length', (string)filesize($response->file['file']));
            $response->header('Accept-Ranges', 'bytes');
        }
        $message = (string)$response;
        return substr($message, 0, strpos($message, "\r\n\r\n") + 4);
    }
}
