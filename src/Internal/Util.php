<?php

declare(strict_types=1);

namespace Leverprice\Internal;

/** @internal */
final class Util
{
    public const MAX_SAFE_INTEGER = 9007199254740991;
    public const ID_RE = '/^[A-Za-z0-9_-]{1,64}$/D';
    private const JS_SPACE = '\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    public static function b64urlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** @throws \UnexpectedValueException */
    public static function b64urlDecode(string $input): string
    {
        if (preg_match('/^[A-Za-z0-9_-]*$/D', $input) !== 1 || strlen($input) % 4 === 1) {
            throw new \UnexpectedValueException('invalid base64url');
        }
        $padded = strtr($input, '-_', '+/') . str_repeat('=', (4 - strlen($input) % 4) % 4);
        $out = base64_decode($padded, true);
        if ($out === false) {
            throw new \UnexpectedValueException('invalid base64url');
        }

        return $out;
    }

    public static function hmac(string $key, string $data): string
    {
        return hash_hmac('sha256', $data, $key, true);
    }

    public static function hmacHex(string $key, string $data, int $chars): string
    {
        return substr(hash_hmac('sha256', $data, $key), 0, $chars);
    }

    public static function randomHex(int $bytes): string
    {
        return bin2hex(random_bytes(max(1, $bytes)));
    }

    public static function bucketFor(string $salt, string $visitorId): int
    {
        $hex = substr(hash('sha256', $salt . ':' . $visitorId), 0, 8);

        return (int) hexdec($hex) % 10000;
    }

    /** @param list<int> $weights */
    public static function pickVariant(array $weights, int $bucket): int
    {
        $acc = 0;
        foreach ($weights as $i => $weight) {
            $acc += $weight;
            if ($bucket < $acc) {
                return $i;
            }
        }

        return max(0, count($weights) - 1);
    }

    public static function isSafeInt(mixed $v): bool
    {
        if (is_int($v)) {
            return $v >= -self::MAX_SAFE_INTEGER && $v <= self::MAX_SAFE_INTEGER;
        }

        return is_float($v) && is_finite($v) && floor($v) === $v && abs($v) <= self::MAX_SAFE_INTEGER;
    }

    public static function safeInt(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v >= -self::MAX_SAFE_INTEGER && $v <= self::MAX_SAFE_INTEGER ? $v : null;
        }

        return is_float($v) && self::isSafeInt($v) ? (int) $v : null;
    }

    public static function number(mixed $v): int|float|null
    {
        return is_int($v) || is_float($v) ? $v : null;
    }

    public static function isId(mixed $v): bool
    {
        return is_string($v) && preg_match(self::ID_RE, $v) === 1;
    }

    public static function trim(string $s): string
    {
        if (self::isUtf8($s)) {
            $out = preg_replace('/^[' . self::JS_SPACE . ']+|[' . self::JS_SPACE . ']+$/uD', '', $s);
            if (is_string($out)) {
                return $out;
            }
        }

        return trim($s, " \t\n\r\x0B\x0C");
    }

    public static function jsSpace(): string
    {
        return self::JS_SPACE;
    }

    public static function isUtf8(string $s): bool
    {
        return preg_match('//u', $s) === 1;
    }

    public static function jsLength(string $s): int
    {
        return (int) preg_match_all('/./su', $s) + (int) preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $s);
    }

    /** @throws \JsonException */
    public static function jsonDecode(string $bytes): mixed
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }

        return json_decode($bytes, false, 512, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** @throws \JsonException */
    public static function jsonEncode(mixed $value): string
    {
        return json_encode($value, self::JSON_FLAGS);
    }

    /** @return list<array{string, mixed}> */
    public static function pairs(\stdClass $o): array
    {
        $out = [];
        foreach (get_object_vars($o) as $k => $v) {
            $out[] = [(string) $k, $v];
        }

        return $out;
    }

    public static function prop(\stdClass $o, string $name): mixed
    {
        return property_exists($o, $name) ? $o->{$name} : null;
    }

    public static function describe(mixed $v): string
    {
        return match (true) {
            $v === null => 'null',
            is_bool($v) => $v ? 'true' : 'false',
            is_string($v) => $v,
            is_int($v), is_float($v) => (string) $v,
            default => get_debug_type($v),
        };
    }

    public static function jsRound(float $v): float
    {
        return floor($v + 0.5);
    }

    public static function isoTime(int|float $ms): string
    {
        if (!is_finite((float) $ms) || abs($ms) > 8.64e15) {
            return 'Invalid Date';
        }
        $seconds = (int) floor($ms / 1000);

        return gmdate('Y-m-d\TH:i:s', $seconds) . sprintf('.%03dZ', (int) ($ms - $seconds * 1000));
    }
}
