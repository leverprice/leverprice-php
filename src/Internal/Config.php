<?php

declare(strict_types=1);

namespace Leverprice\Internal;

/** @internal */
final class Config
{
    /**
     * @param list<string> $keyFingerprints
     * @param array<string, Product> $products
     */
    public function __construct(
        public readonly string $projectId,
        public readonly int $version,
        public readonly int|float $issuedAt,
        public readonly int|float $expiresAt,
        public readonly string $assignmentKey,
        public readonly array $keyFingerprints,
        private readonly array $products,
    ) {}

    public function product(string $key): ?Product
    {
        return $this->products[$key] ?? null;
    }
}
