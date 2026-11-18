<?php

declare(strict_types=1);

namespace Leverprice\Internal;

/** @internal */
final class Experiment
{
    /**
     * @param list<Variant> $variants
     * @param array<string, true>|null $scope
     * @param array<string, list<int>> $splitOf
     */
    public function __construct(
        public readonly string $id,
        public readonly string $salt,
        public readonly array $variants,
        private readonly ?array $scope,
        private readonly array $splitOf,
    ) {}

    public function variant(string $id): ?Variant
    {
        foreach ($this->variants as $v) {
            if ($v->id === $id) {
                return $v;
            }
        }

        return null;
    }

    public function runsIn(?string $country): bool
    {
        return $this->scope === null || ($country !== null && isset($this->scope[$country]));
    }

    /** @return list<int> */
    public function weightsFor(?string $country): array
    {
        if ($country !== null && isset($this->splitOf[$country])) {
            return $this->splitOf[$country];
        }

        return array_map(static fn(Variant $v): int => $v->weight, $this->variants);
    }
}
