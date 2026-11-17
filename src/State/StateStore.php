<?php

declare(strict_types=1);

namespace Leverprice\State;

/** Keeps the SDK's state between requests. Implementations must never throw. */
interface StateStore
{
    public function read(string $key): ?string;

    public function write(string $key, string $value): bool;
}
