<?php

declare(strict_types=1);

namespace Leverprice\Http;

use Leverprice\LeverpriceException;

/** A network error or timeout; set `$timedOut` when the request ran out of time. */
class TransportException extends LeverpriceException
{
    public function __construct(
        string $message,
        public readonly bool $timedOut = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
