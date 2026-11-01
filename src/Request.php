<?php

declare(strict_types=1);

namespace Kuman;

use Workerman\Coroutine\Context;
use Workerman\Protocols\Http\Request as BaseRequest;
use Workerman\Protocols\Http\Response as BaseResponse;

class Request extends BaseRequest
{
    /** @var array<string, string> */
    public array $params = [];

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
        $response = Response::from($value);
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
