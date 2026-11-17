<?php

declare(strict_types=1);

namespace Leverprice\Internal;

/** @internal */
final class Flight
{
    public bool $running = false;
    public bool $triedInline = false;

    public function __construct(
        public readonly int $startedAt,
        public readonly bool $unconditional,
    ) {}
}
