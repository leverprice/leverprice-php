<?php

declare(strict_types=1);

namespace Leverprice\Internal;

/** @internal */
final class Superglobals
{
    public static ?string $sapi = null;

    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function header(string $lower): ?string
    {
        $value = $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $lower))] ?? null;
        if (is_string($value)) {
            return $value;
        }

        return $lower === 'cookie' ? self::cookieHeaderFromCookies() : null;
    }

    public function remoteAddress(): mixed
    {
        return $_SERVER['REMOTE_ADDR'] ?? null;
    }

    public function scopeKey(): ?string
    {
        $sapi = self::$sapi ?? PHP_SAPI;
        if ($sapi === 'cli' || $sapi === 'phpdbg') {
            return null;
        }
        $time = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;

        return is_float($time) || is_int($time) ? (string) $time : null;
    }

    private static function cookieHeaderFromCookies(): ?string
    {
        $parts = [];
        foreach ($_COOKIE as $name => $value) {
            if (is_string($value)) {
                $parts[] = $name . '=' . rawurlencode($value);
            }
        }

        return $parts === [] ? null : implode('; ', $parts);
    }
}
