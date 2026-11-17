<?php

declare(strict_types=1);

namespace Leverprice\State;

/** Shared memory of PHP-FPM workers on one host (ext-apcu). */
final class ApcuStore implements StateStore
{
    public function __construct(private readonly int $ttlSeconds = 604800) {}

    public static function isSupported(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    public function read(string $key): ?string
    {
        try {
            $value = apcu_fetch($key, $found);

            return $found === true && is_string($value) ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function write(string $key, string $value): bool
    {
        try {
            return apcu_store($key, $value, $this->ttlSeconds) === true;
        } catch (\Throwable) {
            return false;
        }
    }
}
