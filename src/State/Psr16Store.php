<?php

declare(strict_types=1);

namespace Leverprice\State;

use Psr\SimpleCache\CacheInterface;

/** Any PSR-16 cache (Redis, Memcached, a framework cache…). */
final class Psr16Store implements StateStore
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly int $ttlSeconds = 604800,
    ) {}

    public function read(string $key): ?string
    {
        try {
            $value = $this->cache->get($key);

            return is_string($value) ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function write(string $key, string $value): bool
    {
        try {
            return $this->cache->set($key, $value, $this->ttlSeconds);
        } catch (\Throwable) {
            return false;
        }
    }
}
