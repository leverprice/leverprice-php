<?php

declare(strict_types=1);

namespace Leverprice\Internal;

use Leverprice\LeverpriceException;

/** @internal */
final class Wire
{
    public const PROTOCOL_VERSION = 2;
    public const CONFIG_VERSION_HEADER = 'Leverprice-Config-Version';
    public const MAX_EVENTS_PER_REQUEST = 500;
    public const PROVIDERS = ['stripe', 'polar', 'lemonsqueezy', 'dodo', 'other'];
    private const MAX_MINOR_UNITS = 10000000000;
    private const SIGNATURE_RE = '/^[0-9a-f]{16}$/D';

    /** @return array{Config, list<string>} */
    public static function parseConfig(string $payload): array
    {
        try {
            $raw = Util::jsonDecode(Util::b64urlDecode($payload));
        } catch (\Throwable) {
            throw new LeverpriceException('Leverprice config: payload is not base64url JSON');
        }
        if (!$raw instanceof \stdClass) {
            throw new LeverpriceException('Leverprice config: payload is not an object');
        }
        if (Util::safeInt(Util::prop($raw, 'schema')) !== self::PROTOCOL_VERSION) {
            throw new LeverpriceException(sprintf(
                'Leverprice config: unsupported schema %s (this SDK speaks schema %d)',
                property_exists($raw, 'schema') ? Util::describe($raw->schema) : 'undefined',
                self::PROTOCOL_VERSION,
            ));
        }
        $fingerprints = [];
        $rawFingerprints = Util::prop($raw, 'keyFingerprints');
        if (!is_array($rawFingerprints)) {
            throw new LeverpriceException('Leverprice config: bad keyFingerprints');
        }
        foreach ($rawFingerprints as $f) {
            if (!is_string($f) || preg_match('/^[0-9a-f]{16}$/D', $f) !== 1) {
                throw new LeverpriceException('Leverprice config: bad keyFingerprints');
            }
            $fingerprints[] = $f;
        }
        $projectId = Util::prop($raw, 'projectId');
        if (!is_string($projectId) || $projectId === '') {
            throw new LeverpriceException('Leverprice config: no projectId');
        }
        $version = Util::safeInt(Util::prop($raw, 'version'));
        if ($version === null || $version < 0) {
            throw new LeverpriceException('Leverprice config: bad version');
        }
        $expiresAt = Util::number(Util::prop($raw, 'expiresAt'));
        if ($expiresAt === null || !is_finite((float) $expiresAt)) {
            throw new LeverpriceException('Leverprice config: bad expiresAt');
        }
        $assignmentKey = Util::prop($raw, 'assignmentKey');
        if (!is_string($assignmentKey) || self::keyLength($assignmentKey) < 32) {
            throw new LeverpriceException('Leverprice config: assignmentKey must be at least 32 bytes of base64url');
        }
        $products = Util::prop($raw, 'products');
        if (!$products instanceof \stdClass) {
            throw new LeverpriceException('Leverprice config: no products');
        }

        $parsed = [];
        $dropped = [];
        foreach (Util::pairs($products) as [$key, $value]) {
            $product = self::parseProduct($value);
            if ($product === null) {
                $dropped[] = $key;
                continue;
            }
            $parsed[$key] = $product;
        }

        return [
            new Config(
                $projectId,
                $version,
                Util::number(Util::prop($raw, 'issuedAt')) ?? 0,
                $expiresAt,
                $assignmentKey,
                $fingerprints,
                $parsed,
            ),
            $dropped,
        ];
    }

    /** @param array{payload: string, signature: string, rootKeyId: string} $signed */
    public static function parseKeyset(array $signed): Keyset
    {
        try {
            $raw = Util::jsonDecode(Util::b64urlDecode($signed['payload']));
        } catch (\Throwable) {
            throw new LeverpriceException('Leverprice keyset: payload is not base64url JSON');
        }
        $version = $raw instanceof \stdClass ? Util::safeInt(Util::prop($raw, 'version')) : null;
        $keys = $raw instanceof \stdClass ? Util::prop($raw, 'keys') : null;
        if (!$raw instanceof \stdClass || Util::safeInt(Util::prop($raw, 'schema')) !== 1 || $version === null || !is_array($keys)) {
            throw new LeverpriceException('Leverprice keyset: unsupported schema');
        }
        $list = [];
        foreach ($keys as $k) {
            if (!$k instanceof \stdClass) {
                continue;
            }
            $id = Util::prop($k, 'id');
            $publicKey = Util::prop($k, 'publicKey');
            if (is_string($id) && is_string($publicKey)) {
                $list[] = new SigningKey($id, $publicKey);
            }
        }

        return new Keyset(
            $version,
            Util::number(Util::prop($raw, 'issuedAt')) ?? 0,
            Util::number(Util::prop($raw, 'expiresAt')),
            $list,
            $signed,
        );
    }

    /** @param array<string, mixed> $e */
    public static function eventProblem(array $e): ?string
    {
        $timestamp = Util::safeInt($e['timestamp'] ?? null);
        if ($timestamp === null || $timestamp <= 0) {
            return 'timestamp must be a positive integer (ms)';
        }
        $type = $e['type'] ?? null;
        if ($type === 'exposure') {
            return Util::isId($e['experimentId'] ?? null) && Util::isId($e['variantId'] ?? null) && Util::isId($e['visitorId'] ?? null)
                ? null
                : 'invalid experiment/variant/visitor id';
        }
        if ($type !== 'purchase' && $type !== 'refund') {
            return 'unknown event type';
        }
        $provider = $e['provider'] ?? null;
        if (!in_array($provider, self::PROVIDERS, true)) {
            return sprintf('unknown provider "%s"', Util::describe($provider));
        }
        if (!self::isText($e['orderId'] ?? null)) {
            return 'orderId must be a string of 1–255 characters';
        }
        $currency = $e['currency'] ?? null;
        if (!is_string($currency) || preg_match('/^[a-z]{3}$/D', $currency) !== 1) {
            return 'currency must be a 3-letter ISO 4217 code';
        }
        if ($type === 'purchase') {
            if (!self::isMinorUnits($e['amount'] ?? null)) {
                return 'amount must be a non-negative integer in minor units';
            }
            $attribution = $e['attribution'] ?? null;

            return $attribution === null || self::isAttribution($attribution) ? null : 'invalid attribution';
        }
        $hasRefundId = array_key_exists('refundId', $e);
        $hasAmount = array_key_exists('amount', $e);
        $hasTotal = array_key_exists('refundedTotal', $e);
        if ($hasRefundId && !self::isText($e['refundId'])) {
            return 'refundId must be a string of 1–255 characters';
        }
        if ($hasAmount && !self::isMinorUnits($e['amount'])) {
            return 'amount must be a non-negative integer';
        }
        if ($hasTotal && !self::isMinorUnits($e['refundedTotal'])) {
            return 'refundedTotal must be a non-negative integer';
        }
        if (!($hasRefundId && $hasAmount) && !$hasTotal) {
            return 'refund needs refundId + amount, or refundedTotal';
        }

        return null;
    }

    private static function parseProduct(mixed $p): ?Product
    {
        if (!$p instanceof \stdClass) {
            return null;
        }
        $currency = Util::prop($p, 'currency');
        $price = Util::safeInt(Util::prop($p, 'price'));
        $minPrice = Util::safeInt(Util::prop($p, 'minPrice'));
        $maxPrice = Util::safeInt(Util::prop($p, 'maxPrice'));
        if (!is_string($currency) || preg_match('/^[A-Za-z]{3}$/D', $currency) !== 1) {
            return null;
        }
        if ($price === null || $minPrice === null || $maxPrice === null) {
            return null;
        }
        $experiment = null;
        $e = Util::prop($p, 'experiment');
        if ($e !== null) {
            $experiment = self::parseExperiment($e);
            if ($experiment === null) {
                return null;
            }
        }
        $id = Util::prop($p, 'id');

        return new Product(
            is_string($id) ? $id : '',
            strtolower($currency),
            $price,
            $minPrice,
            $maxPrice,
            self::httpsUrl(Util::prop($p, 'checkoutUrl')),
            $experiment,
        );
    }

    private static function parseExperiment(mixed $e): ?Experiment
    {
        if (!$e instanceof \stdClass) {
            return null;
        }
        $id = Util::prop($e, 'id');
        $salt = Util::prop($e, 'salt');
        $variants = Util::prop($e, 'variants');
        if (!is_string($id) || !Util::isId($id) || !is_string($salt) || !is_array($variants)) {
            return null;
        }
        $list = [];
        foreach ($variants as $v) {
            if (!$v instanceof \stdClass) {
                return null;
            }
            $vid = Util::prop($v, 'id');
            $price = Util::safeInt(Util::prop($v, 'price'));
            $weight = Util::safeInt(Util::prop($v, 'weight'));
            if (!is_string($vid) || !Util::isId($vid) || $price === null || $weight === null || $weight < 0) {
                return null;
            }
            $list[] = new Variant($vid, $price, $weight, self::httpsUrl(Util::prop($v, 'checkoutUrl')));
        }

        return new Experiment($id, $salt, $list);
    }

    private static function httpsUrl(mixed $v): ?string
    {
        if (!is_string($v) || !Util::isUtf8($v)) {
            return null;
        }

        return preg_match('/^https:\/\/[^' . Util::jsSpace() . ']+$/iuD', $v) === 1 ? $v : null;
    }

    private static function keyLength(string $b64): int
    {
        try {
            return strlen(Util::b64urlDecode($b64));
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function isAttribution(mixed $a): bool
    {
        if (!is_array($a)) {
            return false;
        }
        $sig = $a['signature'] ?? null;

        return Util::isId($a['experimentId'] ?? null)
            && Util::isId($a['variantId'] ?? null)
            && Util::isId($a['visitorId'] ?? null)
            && ($sig === null || (is_string($sig) && preg_match(self::SIGNATURE_RE, $sig) === 1));
    }

    private static function isText(mixed $v): bool
    {
        if (!is_string($v) || !Util::isUtf8($v)) {
            return false;
        }
        $length = Util::jsLength($v);

        return $length >= 1 && $length <= 255;
    }

    private static function isMinorUnits(mixed $v): bool
    {
        $n = Util::safeInt($v);

        return $n !== null && $n >= 0 && $n <= self::MAX_MINOR_UNITS;
    }
}
