<?php

declare(strict_types=1);

namespace Leverprice\Internal;

/** @internal */
final class Keyset
{
    /**
     * @param list<SigningKey> $keys
     * @param array{payload: string, signature: string, rootKeyId: string} $signed
     */
    public function __construct(
        public readonly int $version,
        public readonly int|float $issuedAt,
        public readonly int|float|null $expiresAt,
        public readonly array $keys,
        public readonly array $signed,
    ) {}
}
