<?php

declare(strict_types=1);

namespace Kuman\Auth;

use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use RuntimeException;
use SensitiveParameter;

/**
 * Issues and verifies signed JSON Web Tokens, RFC 7519. HMAC needs a secret, RSA and
 * ECDSA a PEM key and ext-openssl. A public key verifies but cannot issue.
 */
final class Jwt
{
    /** Hash, then the shortest secret for HMAC, the shortest key for RSA, the integer size for ECDSA, in bytes. */
    private const ALGORITHMS = [
        'HS256' => ['sha256', 32],
        'HS384' => ['sha384', 48],
        'HS512' => ['sha512', 64],
        'RS256' => ['sha256', 256],
        'RS384' => ['sha384', 256],
        'RS512' => ['sha512', 256],
        'ES256' => ['sha256', 32],
        'ES384' => ['sha384', 48],
    ];

    private const CURVES = ['ES256' => 'prime256v1', 'ES384' => 'secp384r1'];

    private string $family;

    private string $hash;

    private int $size;

    private string|OpenSSLAsymmetricKey $key;

    private ?OpenSSLAsymmetricKey $publicKey = null;

    /**
     * @param string $key A secret for HS*, a PEM key for RS* and ES*.
     * @param ?int $ttl Seconds an issued token lasts, null for no expiry.
     * @param ?string $issuer Written to issued tokens and required of verified ones.
     * @param ?string $audience Written to issued tokens and required of verified ones.
     * @param int $leeway Seconds of clock difference to tolerate.
     */
    public function __construct(
        #[SensitiveParameter] string $key,
        private readonly string $algorithm = 'HS256',
        private readonly ?int $ttl = 3600,
        private readonly ?string $issuer = null,
        private readonly ?string $audience = null,
        private readonly int $leeway = 0,
    ) {
        if (!isset(self::ALGORITHMS[$algorithm])) {
            throw new InvalidArgumentException("Unsupported JWT algorithm: $algorithm");
        }
        [$this->hash, $this->size] = self::ALGORITHMS[$algorithm];
        $this->family = substr($algorithm, 0, 2);
        if ($this->family === 'HS') {
            $this->key = $this->secret($key);
            return;
        }
        $this->key = $this->pem($key);
        // openssl_verify() does not take a private key, and one PEM file may do both jobs.
        $this->publicKey = openssl_pkey_get_public(openssl_pkey_get_details($this->key)['key']);
    }

    /**
     * Claims may replace the defaults: issue(['sub' => 7, 'exp' => time() + 60]).
     *
     * @param array<string, mixed> $claims
     */
    public function issue(array $claims = []): string
    {
        $now = time();
        $claims += array_filter([
            'iat' => $now,
            'exp' => $this->ttl === null ? null : $now + $this->ttl,
            'iss' => $this->issuer,
            'aud' => $this->audience,
        ], static fn(mixed $claim) => $claim !== null);

        $input = self::json(['typ' => 'JWT', 'alg' => $this->algorithm]) . '.' . self::json($claims);
        return $input . '.' . self::encode($this->sign($input));
    }

    /**
     * @return ?array<string, mixed> The claims, null for a token that fails any check.
     */
    public function verify(#[SensitiveParameter] string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$head, $body, $signature] = array_map(self::decode(...), $parts);
        $header = json_decode((string)$head, true);

        // The configuration picks the algorithm. A token that could pick it would choose
        // "none", or HS256 with the public RSA key as the secret.
        if (!is_array($header) || ($header['alg'] ?? null) !== $this->algorithm || array_key_exists('crit', $header)) {
            return null;
        }
        if ($signature === null || !$this->matches("$parts[0].$parts[1]", $signature)) {
            return null;
        }

        $claims = json_decode((string)$body, true);
        return is_array($claims) && $this->accepts($claims) ? $claims : null;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function accepts(array $claims): bool
    {
        $exp = $claims['exp'] ?? null;
        $nbf = $claims['nbf'] ?? null;
        foreach ([$exp, $nbf] as $time) {
            if ($time !== null && !is_int($time) && !is_float($time)) {
                return false;
            }
        }

        $now = time();
        return ($exp === null || $now < $exp + $this->leeway)
            && ($nbf === null || $now + $this->leeway >= $nbf)
            && ($this->issuer === null || ($claims['iss'] ?? null) === $this->issuer)
            && ($this->audience === null || in_array($this->audience, (array)($claims['aud'] ?? []), true));
    }

    private function sign(string $input): string
    {
        if ($this->family === 'HS') {
            return hash_hmac($this->hash, $input, $this->key, true);
        }
        if (!@openssl_sign($input, $signature, $this->key, $this->hash)) {
            throw new RuntimeException('Issuing a JWT needs the private key.');
        }
        return $this->family === 'ES' ? self::fromDer($signature, $this->size) : $signature;
    }

    private function matches(string $input, string $signature): bool
    {
        if ($this->family === 'HS') {
            return hash_equals($this->sign($input), $signature);
        }
        if ($this->family === 'ES') {
            if (strlen($signature) !== 2 * $this->size) {
                return false;
            }
            $signature = self::toDer($signature, $this->size);
        }
        return openssl_verify($input, $signature, $this->publicKey, $this->hash) === 1;
    }

    private function secret(string $key): string
    {
        // RFC 7518 section 3.2: the key must be at least as long as the hash output.
        if (strlen($key) < $this->size) {
            throw new InvalidArgumentException("$this->algorithm needs a secret of at least $this->size bytes.");
        }
        return $key;
    }

    private function pem(string $key): OpenSSLAsymmetricKey
    {
        if (!extension_loaded('openssl')) {
            throw new RuntimeException("$this->algorithm needs ext-openssl.");
        }

        $parsed = openssl_pkey_get_private($key) ?: openssl_pkey_get_public($key);
        $details = $parsed === false ? false : openssl_pkey_get_details($parsed);
        if ($details === false) {
            throw new InvalidArgumentException("$this->algorithm needs a PEM key.");
        }

        $fits = $this->family === 'RS'
            ? $details['type'] === OPENSSL_KEYTYPE_RSA && $details['bits'] >= 8 * $this->size
            : ($details['ec']['curve_name'] ?? null) === self::CURVES[$this->algorithm];
        if (!$fits) {
            throw new InvalidArgumentException("The key does not fit $this->algorithm.");
        }
        return $parsed;
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function json(array $value): string
    {
        return self::encode(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function decode(string $data): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+\z/', $data)) {
            return null;
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }

    /**
     * OpenSSL writes ECDSA signatures as DER, JWT as two integers of fixed size.
     */
    private static function fromDer(string $der, int $size): string
    {
        $raw = '';
        $offset = 2;
        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $value = ltrim(substr($der, $offset + 2, $length), "\0");
            $raw .= str_pad($value, $size, "\0", STR_PAD_LEFT);
            $offset += 2 + $length;
        }
        return $raw;
    }

    private static function toDer(string $raw, int $size): string
    {
        $body = '';
        foreach (str_split($raw, $size) as $integer) {
            $integer = ltrim($integer, "\0");
            // DER integers are signed, so a set top bit needs a zero byte in front.
            if ($integer === '' || ord($integer[0]) > 0x7f) {
                $integer = "\0$integer";
            }
            $body .= "\x02" . chr(strlen($integer)) . $integer;
        }
        return "\x30" . chr(strlen($body)) . $body;
    }
}
