<?php

declare(strict_types=1);

namespace Leverprice\Internal;

use Leverprice\LeverpriceException;

/**
 * A refused config body, with the code sent back in `Leverprice-Config-Error`.
 *
 * @internal
 */
final class ConfigError extends LeverpriceException
{
    public const MALFORMED = 'malformed';
    public const UNTRUSTED = 'untrusted';
    public const BAD_SIGNATURE = 'bad_signature';
    public const EXPIRED = 'expired';
    public const WRONG_KEY = 'wrong_key';
    public const WRONG_PROJECT = 'wrong_project';
    public const ROLLBACK = 'rollback';
    public const CODES = [
        self::MALFORMED, self::UNTRUSTED, self::BAD_SIGNATURE, self::EXPIRED,
        self::WRONG_KEY, self::WRONG_PROJECT, self::ROLLBACK,
    ];

    public function __construct(
        public readonly string $refusal,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function wrap(string $refusal, \Throwable $error): self
    {
        return $error instanceof self ? $error : new self($refusal, $error->getMessage(), $error);
    }
}
