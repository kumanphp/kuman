<?php

declare(strict_types=1);

namespace Kuman;

use stdClass;
use Workerman\Connection\ConnectionInterface;
use Workerman\Protocols\ProtocolInterface;
use Workerman\Protocols\Websocket as BaseWebsocket;

/**
 * Lets WebSocket share a port with HTTP.
 *
 * TcpConnection keeps one decode cache per process, keyed by the raw buffer, for
 * every protocol. A cached WebSocket message would be a string, and an HTTP
 * connection sending the same bytes then crashes the worker on that cache hit.
 * Objects are never cached, so messages are wrapped.
 */
final class WebSocket implements ProtocolInterface
{
    public static function input(string $buffer, ConnectionInterface $connection): int
    {
        return BaseWebsocket::input($buffer, $connection);
    }

    public static function decode(string $buffer, ConnectionInterface $connection): stdClass
    {
        return (object)['data' => BaseWebsocket::decode($buffer, $connection)];
    }

    public static function encode(mixed $data, ConnectionInterface $connection): string
    {
        return BaseWebsocket::encode($data, $connection);
    }
}
