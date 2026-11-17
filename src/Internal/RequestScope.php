<?php

declare(strict_types=1);

namespace Leverprice\Internal;

/** @internal */
final class RequestScope
{
    public ?string $cookieInput = null;
    public ?string $userId = null;
    public ?string $assignmentKey = null;
    public ?AssignmentState $state = null;
    public bool $pinned = false;
}
