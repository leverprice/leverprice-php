<?php

declare(strict_types=1);

namespace Leverprice\State;

/** A store that can read, modify and write one key under an exclusive lock. */
interface LockingStateStore extends StateStore
{
    /** @param callable(?string): ?string $mutate returns the new value, or null to leave it unchanged */
    public function update(string $key, callable $mutate): bool;
}
