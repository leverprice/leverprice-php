<?php

declare(strict_types=1);

namespace Leverprice\Internal;

/** @internal */
final class Product
{
    public function __construct(
        public readonly string $id,
        public readonly string $currency,
        public readonly int $price,
        public readonly int $minPrice,
        public readonly int $maxPrice,
        public readonly ?string $checkoutUrl,
        public readonly ?Experiment $experiment,
    ) {}
}
