<?php

declare(strict_types=1);

namespace Leverprice\Internal;

/** @internal */
final class Guard
{
    public function __construct(
        public readonly string $currency,
        public readonly int $fallbackPrice,
        public readonly int $minPrice,
        public readonly int $maxPrice,
    ) {}

    public function allows(Product $product, int $price): bool
    {
        return $price > 0
            && $price >= max($this->minPrice, $product->minPrice)
            && $price <= min($this->maxPrice, $product->maxPrice);
    }
}
