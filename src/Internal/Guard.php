<?php

declare(strict_types=1);

namespace Leverprice\Internal;

/** @internal */
final class Guard
{
    public function __construct(
        public readonly string $currency,
        public readonly int $fallbackPrice,
    ) {}
}
