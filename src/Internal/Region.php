<?php

declare(strict_types=1);

namespace Leverprice\Internal;

/** @internal */
final class Region
{
    /** @param list<string> $countries */
    public function __construct(
        public readonly int $index,
        public readonly array $countries,
        public readonly string $currency,
        public readonly int $price,
    ) {}
}
