<?php

declare(strict_types=1);

namespace Leverprice\Internal;

/** @internal */
final class RequestInfo
{
    /** @param list<string> $assignmentCookies */
    public function __construct(
        public readonly ?string $cookieInput,
        public readonly array $assignmentCookies,
        public readonly bool $pinned,
        public readonly ?string $userAgent,
        public readonly bool $prefetch,
    ) {}
}
