<?php

declare(strict_types=1);

namespace Leverprice\Internal;

/** @internal */
final class Experiment
{
    /** @param list<Variant> $variants */
    public function __construct(
        public readonly string $id,
        public readonly string $salt,
        public readonly array $variants,
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
}
