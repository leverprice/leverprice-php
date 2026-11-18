<?php

declare(strict_types=1);

namespace Leverprice\Internal;

/** @internal */
final class Region
{
    /**
     * @param list<string> $countries
     * @param int $level the price as basis points of the standard price (10000 = the same)
     */
    public function __construct(
        public readonly int $index,
        public readonly array $countries,
        public readonly string $currency,
        public readonly int $price,
        public readonly int $level = Wire::STANDARD_LEVEL,
    ) {}
}
