<?php

declare(strict_types=1);

namespace Kuman;

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\ServerSentEvents;
use Workerman\Timer;

/**
 * One open event stream. Its timers stop when the client disconnects.
 */
final class Sse
{
    /** @var array<int, int> */
    private array $timers = [];

    /** @var array<int, callable> */
    private array $closeCallbacks = [];

    public function __construct(private readonly TcpConnection $connection, private readonly App $app)
    {
        $connection->onClose = function (): void {
            array_map(Timer::del(...), $this->timers);
            $this->timers = [];
            foreach ($this->closeCallbacks as $callback) {
                $this->app->guard($callback)();
            }
        };
    }

    public function send(string|array $data, ?string $event = null, ?string $id = null): void
    {
        $data = is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : $data;
        $clean = static fn(?string $field) => $field === null ? null : str_replace(["\r", "\n"], '', $field);

        $this->connection->send(new ServerSentEvents(array_filter([
            'event' => $clean($event),
            'id' => $clean($id),
            'data' => str_replace(["\r\n", "\r"], "\n", $data),
        ], static fn(?string $field) => $field !== null)));
    }

    public function every(float $seconds, callable $callback): void
    {
        $this->timers[] = Timer::add($seconds, $this->app->guard($callback));
    }

    public function onClose(callable $callback): void
    {
        $this->closeCallbacks[] = $callback;
    }

    public function close(): void
    {
        $this->connection->close();
    }
}
