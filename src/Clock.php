<?php

declare(strict_types=1);

namespace Leverprice;

/** Time source in milliseconds since the epoch; pass your own as the `clock` option in tests. */
interface Clock
{
    public function now(): int;

    public function sleep(int $milliseconds): void;
}
