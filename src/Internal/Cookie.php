<?php

declare(strict_types=1);

namespace Leverprice\Internal;

/** @internal */
final class Cookie
{
    private const MAX_LENGTH = 4096;
    private const PREFIX = 'pp:cookie:v1:';

    public static function sign(string $key, AssignmentState $state): string
    {
        $body = Util::b64urlEncode($state->toJson());

        return $body . '.' . Util::b64urlEncode(substr(Util::hmac($key, self::PREFIX . $body), 0, 16));
    }

    public static function verify(string $key, string $raw): ?AssignmentState
    {
        if (strlen($raw) > self::MAX_LENGTH) {
            return null;
        }
        $dot = strpos($raw, '.');
        if ($dot === false || $dot === 0) {
            return null;
        }
        $body = substr($raw, 0, $dot);
        $sig = substr($raw, $dot + 1);
        if (preg_match('/^[A-Za-z0-9_-]+$/D', $body) !== 1 || preg_match('/^[A-Za-z0-9_-]{22}$/D', $sig) !== 1) {
            return null;
        }
        try {
            $expected = substr(Util::hmac($key, self::PREFIX . $body), 0, 16);
            if (!hash_equals($expected, Util::b64urlDecode($sig))) {
                return null;
            }

            return self::normalize(Util::jsonDecode(Util::b64urlDecode($body)));
        } catch (\Throwable) {
            return null;
        }
    }

    public static function setCookieHeader(string $name, string $value, int $maxAge, bool $secure, ?string $domain): string
    {
        $parts = [$name . '=' . $value, 'Path=/', 'Max-Age=' . $maxAge, 'SameSite=Lax', 'HttpOnly'];
        if ($secure) {
            $parts[] = 'Secure';
        }
        if ($domain !== null && $domain !== '') {
            $parts[] = 'Domain=' . $domain;
        }

        return implode('; ', $parts);
    }

    private static function normalize(mixed $raw): ?AssignmentState
    {
        if (!$raw instanceof \stdClass) {
            return null;
        }
        $v = Util::prop($raw, 'v');
        $a = Util::prop($raw, 'a');
        if (!is_string($v) || preg_match('/^[0-9a-f]{32}$/D', $v) !== 1 || !$a instanceof \stdClass) {
            return null;
        }
        $state = new AssignmentState($v);
        foreach (array_slice(Util::pairs($a), 0, AssignmentState::MAX_EXPERIMENTS) as [$exp, $variant]) {
            if (Util::isId($exp) && is_string($variant) && Util::isId($variant)) {
                $state->a[] = [$exp, $variant];
            }
        }
        $x = Util::prop($raw, 'x');
        if (is_array($x)) {
            foreach ($x as $id) {
                if (is_string($id) && $state->variantFor($id) !== null) {
                    $state->x[] = $id;
                }
            }
            $state->x = array_slice($state->x, 0, AssignmentState::MAX_EXPERIMENTS);
        }

        return $state;
    }
}
