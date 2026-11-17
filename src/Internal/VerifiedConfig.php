<?php

declare(strict_types=1);

namespace Leverprice\Internal;

/** @internal */
final class VerifiedConfig
{
    /** @param list<string> $dropped */
    public function __construct(
        public readonly Config $config,
        public readonly SigningKey $signingKey,
        public readonly array $dropped,
    ) {}
}
