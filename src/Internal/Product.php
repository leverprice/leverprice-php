<?php

declare(strict_types=1);

namespace Leverprice\Internal;

/** @internal */
final class Product
{
    /** @param array<string, Region> $regionOf */
    public function __construct(
        public readonly string $id,
        public readonly string $currency,
        public readonly int $price,
        public readonly int $minPrice,
        public readonly int $maxPrice,
        public readonly ?string $checkoutUrl,
        private readonly ?Experiment $experiment,
        private readonly array $regionOf,
    ) {}

    public function inRange(int $price): bool
    {
        return $price > 0 && $price >= $this->minPrice && $price <= $this->maxPrice;
    }

    public function experimentFor(?string $country): ?Experiment
    {
        return $this->experiment !== null && $this->experiment->runsIn($country) ? $this->experiment : null;
    }

    public function region(string $country): ?Region
    {
        return $this->regionOf[$country] ?? null;
    }

    public function hasRegions(): bool
    {
        return $this->regionOf !== [];
    }
}
