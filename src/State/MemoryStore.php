<?php

declare(strict_types=1);

namespace Leverprice\State;

/** Keeps state in this process only (`'cache' => false`). */
final class MemoryStore implements LockingStateStore
{
    /** @var array<string, string> */
    private array $values = [];

    public function read(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function write(string $key, string $value): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function update(string $key, callable $mutate): bool
    {
        $value = $mutate($this->read($key));

        return $value === null || $this->write($key, $value);
    }
}
