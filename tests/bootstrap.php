<?php

declare(strict_types=1);

use Leverprice\Internal\Ed25519;

require dirname(__DIR__) . '/vendor/autoload.php';

if (getenv('LEVERPRICE_TEST_SODIUM_COMPAT') === '1') {
    if (!class_exists('ParagonIE_Sodium_Compat')) {
        fwrite(STDERR, "LEVERPRICE_TEST_SODIUM_COMPAT=1 needs paragonie/sodium_compat\n");
        exit(1);
    }
    ParagonIE_Sodium_Compat::$disableFallbackForUnitTests = true;
    Ed25519::$mode = Ed25519::COMPAT;
}
