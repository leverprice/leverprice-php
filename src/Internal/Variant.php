<?php

declare(strict_types=1);

namespace Leverprice\Internal;

/** @internal */
final class Variant
{
    /** @param list<int> $regional */
    public function __construct(
        public readonly string $id,
        public readonly int $price,
        public readonly int $weight,
        public readonly ?string $checkoutUrl,
        public readonly array $regional,
    ) {}
}
