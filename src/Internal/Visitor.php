<?php

declare(strict_types=1);

namespace Leverprice\Internal;

/**
 * The visitor behind a request: the HMAC key, the user id and the cookie state.
 *
 * @internal
 */
final class Visitor
{
    public function __construct(
        public readonly string $key,
        public readonly ?string $userId,
        public readonly AssignmentState $state,
        public bool $changed,
    ) {}
}
