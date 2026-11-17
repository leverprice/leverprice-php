<?php

declare(strict_types=1);

namespace Leverprice\Internal;

/** @internal */
final class Ed25519
{
    public const NATIVE = 'native';
    public const COMPAT = 'compat';
    public const NONE = 'none';

    public static ?string $mode = null;

    public static function available(): bool
    {
        return self::implementation() !== self::NONE;
    }

    public static function verify(string $publicKey, string $signature, string $data): bool
    {
        try {
            $pk = Util::b64urlDecode($publicKey);
            $sig = Util::b64urlDecode($signature);
            if (strlen($pk) !== 32 || strlen($sig) !== 64) {
                return false;
            }

            return match (self::implementation()) {
                self::NATIVE => sodium_crypto_sign_verify_detached($sig, $data, $pk),
                self::COMPAT => self::compat($sig, $data, $pk),
                default => false,
            };
        } catch (\Throwable) {
            return false;
        }
    }

    private static function implementation(): string
    {
        if (self::$mode !== null) {
            return self::$mode;
        }
        if (function_exists('sodium_crypto_sign_verify_detached')) {
            return self::NATIVE;
        }

        return class_exists('ParagonIE_Sodium_Compat') ? self::COMPAT : self::NONE;
    }

    private static function compat(string $sig, string $data, string $pk): bool
    {
        return class_exists('ParagonIE_Sodium_Compat')
            && \ParagonIE_Sodium_Compat::crypto_sign_verify_detached($sig, $data, $pk);
    }
}
