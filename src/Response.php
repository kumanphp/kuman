<?php

declare(strict_types=1);

namespace Kuman;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Stringable;
use Workerman\Protocols\Http\Response as BaseResponse;

/**
 * An immutable PSR-7 response. Workerman only sees it when it is sent, see toWorkerman().
 */
class Response implements ResponseInterface
{
    use Message;

    private string|StreamInterface $body;

    /** @var ?array{file: string, offset: int, length: int} Sent from disk instead of the body. */
    private ?array $file = null;

    /**
     * @param array<string, string|array<int, string>> $headers
     */
    public function __construct(
        private int $status = 200,
        array $headers = [],
        string|StreamInterface $body = '',
        private string $version = '1.1',
        private string $reason = '',
    ) {
        self::assertStatus($status);
        $this->setHeaders($headers);
        $this->body = $body;
        $this->version = self::protocolVersion($version);
        $this->reason = self::reasonPhrase($reason);
    }

    /**
     * Strings become HTML, a response from any PSR-7 library is taken over, and anything
     * else becomes JSON.
     */
    public static function from(mixed $value): static
    {
        return match (true) {
            $value instanceof static => $value,
            $value instanceof ResponseInterface => new static(
                $value->getStatusCode(),
                $value->getHeaders(),
                $value->getBody(),
                $value->getProtocolVersion(),
                $value->getReasonPhrase()
            ),
            $value instanceof BaseResponse => self::fromWorkerman($value),
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
        if ($request === null || !is_file($path)) {
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

    public function getProtocolVersion(): string
    {
        return $this->version;
    }

    public function withProtocolVersion(string $version): static
    {
        $clone = clone $this;
        $clone->version = self::protocolVersion($version);
        return $clone;
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getReasonPhrase(): string
    {
        return $this->reason !== '' ? $this->reason : BaseResponse::PHRASES[$this->status] ?? '';
    }

    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        self::assertStatus($code);
        $clone = clone $this;
        $clone->status = $code;
        $clone->reason = self::reasonPhrase($reasonPhrase);
        return $clone;
    }

    public function getBody(): StreamInterface
    {
        if ($this->body instanceof StreamInterface) {
            return $this->body;
        }
        $stream = $this->file !== null && $this->body === '' ? $this->fileStream($this->file) : Stream::from($this->body);
        return $this->body = $stream;
    }

    public function withBody(StreamInterface $body): static
    {
        $clone = clone $this;
        $clone->body = $body;
        $clone->file = null;
        return $clone;
    }

    /**
     * Send a file from disk, the whole of it or a slice of $length bytes from $offset.
     */
    public function withFile(string $path, int $offset = 0, int $length = 0): static
    {
        if (!is_file($path)) {
            return static::html('<h3>404 Not Found</h3>', 404);
        }
        $clone = clone $this;
        $clone->body = '';
        $clone->file = ['file' => $path, 'offset' => $offset, 'length' => $length];
        return $clone;
    }

    /**
     * Add a Set-Cookie header, every cookie gets its own.
     */
    public function withCookie(
        string $name,
        string $value = '',
        ?int $maxAge = null,
        string $path = '',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = false,
        string $sameSite = ''
    ): static {
        return $this->withAddedHeader('Set-Cookie', $name . '=' . rawurlencode($value)
            . ($domain === '' ? '' : "; Domain=$domain")
            . ($maxAge === null ? '' : "; Max-Age=$maxAge")
            . ($path === '' ? '' : "; Path=$path")
            . (!$secure ? '' : '; Secure')
            . (!$httpOnly ? '' : '; HttpOnly')
            . ($sameSite === '' ? '' : "; SameSite=$sameSite"));
    }

    /**
     * What the connection sends. Repeated headers are joined, except Set-Cookie.
     */
    public function toWorkerman(): BaseResponse
    {
        $headers = [];
        foreach ($this->headerMap() as [$name, $values]) {
            $headers[$name] = match (true) {
                count($values) === 1 => $values[0],
                strcasecmp($name, 'Set-Cookie') === 0 => $values,
                default => implode(', ', $values),
            };
        }

        $body = match (true) {
            $this->file !== null => '',
            $this->body instanceof StreamInterface => (string)$this->body,
            default => $this->body,
        };
        $response = new BaseResponse($this->status, $headers, $body);
        $response->withProtocolVersion($this->version);
        if ($this->reason !== '') {
            $response->withStatus($this->status, $this->reason);
        }
        $response->file = $this->file;
        return $response;
    }

    protected function initialHeaders(): array
    {
        return [];
    }

    private static function fromWorkerman(BaseResponse $response): static
    {
        $converted = new static(
            $response->getStatusCode(),
            $response->getHeaders(),
            $response->rawBody(),
            reason: (string)$response->getReasonPhrase()
        );
        $converted->file = $response->file;
        return $converted;
    }

    /**
     * @param array{file: string, offset: int, length: int} $file
     */
    private function fileStream(array $file): StreamInterface
    {
        $source = @fopen($file['file'], 'rb');
        if ($source === false) {
            return Stream::from();
        }
        $slice = fopen('php://temp', 'r+b');
        $length = $file['length'] > 0 ? $file['length'] : null;
        stream_copy_to_stream($source, $slice, $length, $file['offset']);
        fclose($source);
        rewind($slice);
        return new Stream($slice);
    }

    private static function assertStatus(int $status): void
    {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException("Invalid status code: $status");
        }
    }

    private static function protocolVersion(string $version): string
    {
        if (!preg_match('/^\d(\.\d)?$/', $version)) {
            throw new InvalidArgumentException("Invalid protocol version: $version");
        }
        return $version;
    }

    private static function reasonPhrase(string $reason): string
    {
        return str_replace(["\r", "\n"], '', $reason);
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
