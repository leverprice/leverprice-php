<?php

declare(strict_types=1);

namespace Leverprice\Internal;

/** @internal */
final class Config
{
    /**
     * @param list<string> $keyFingerprints
     * @param array<string, Product> $products
     * @param int $countryHold seconds a visitor keeps the price of a dearer country they were seen in; 0: off
     */
    public function __construct(
        public readonly string $projectId,
        public readonly int $version,
        public readonly int|float $issuedAt,
        public readonly int|float $expiresAt,
        public readonly string $assignmentKey,
        public readonly array $keyFingerprints,
        public readonly ?string $countryHeader,
        private readonly array $products,
        public readonly int $countryHold = 0,
    ) {}

    public function product(string $key): ?Product
    {
        return $this->products[$key] ?? null;
    }
}
