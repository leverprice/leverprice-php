<?php

declare(strict_types=1);

namespace Leverprice\Internal;

/** @internal */
final class SigningKey
{
    public function __construct(
        public readonly string $id,
        public readonly string $publicKey,
    ) {}
}
