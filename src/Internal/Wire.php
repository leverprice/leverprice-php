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
    public const MAX_REGIONS = 300;
    public const MAX_SPLITS = 300;
    public const MAX_SCOPE = 300;
    public const PROVIDERS = ['stripe', 'polar', 'lemonsqueezy', 'dodo', 'paddle', 'creem', 'other'];
    private const MAX_MINOR_UNITS = 10000000000;
    private const SIGNATURE_RE = '/^[0-9a-f]{16}$/D';
    private const HEADER_NAME_RE = '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D';

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
                self::headerName(Util::prop($raw, 'countryHeader')),
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
            if (!Util::isId($e['experimentId'] ?? null) || !Util::isId($e['variantId'] ?? null) || !Util::isId($e['visitorId'] ?? null)) {
                return 'invalid experiment/variant/visitor id';
            }

            return !array_key_exists('country', $e) || Util::isCountry($e['country'])
                ? null
                : 'country must be an ISO 3166-1 alpha-2 code';
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
        $regions = self::parseRegions(Util::prop($p, 'regions'));
        if ($regions === null) {
            return null;
        }
        $regionOf = [];
        foreach ($regions as $region) {
            foreach ($region->countries as $country) {
                if (isset($regionOf[$country])) {
                    return null;
                }
                $regionOf[$country] = $region;
            }
        }
        $open = Util::prop($p, 'experiment');
        $scoped = Util::prop($p, 'scopedExperiment');
        if ($open !== null && $scoped !== null) {
            return null;
        }
        $experiment = null;
        if ($open !== null || $scoped !== null) {
            $experiment = self::parseExperiment($open ?? $scoped, count($regions), $scoped !== null);
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
            $regionOf,
        );
    }

    /** @return list<Region>|null */
    private static function parseRegions(mixed $raw): ?array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw) || count($raw) > self::MAX_REGIONS) {
            return null;
        }
        $regions = [];
        foreach ($raw as $r) {
            if (!$r instanceof \stdClass) {
                return null;
            }
            $countries = self::parseCountries(Util::prop($r, 'countries'));
            $currency = Util::prop($r, 'currency');
            $price = self::price(Util::prop($r, 'price'));
            if ($countries === null || $price === null) {
                return null;
            }
            if (!is_string($currency) || preg_match('/^[A-Za-z]{3}$/D', $currency) !== 1) {
                return null;
            }
            $regions[] = new Region(count($regions), $countries, strtolower($currency), $price);
        }

        return $regions;
    }

    /** @return list<string>|null */
    private static function parseCountries(mixed $raw, int $max = PHP_INT_MAX): ?array
    {
        if (!is_array($raw) || $raw === [] || count($raw) > $max) {
            return null;
        }
        $countries = [];
        foreach ($raw as $country) {
            if (!is_string($country) || !Util::isCountry($country)) {
                return null;
            }
            $countries[] = $country;
        }

        return $countries;
    }

    /** @return list<int>|null */
    private static function parseRegional(mixed $raw, int $regions): ?array
    {
        if ($regions === 0) {
            return [];
        }
        if (!is_array($raw) || count($raw) !== $regions) {
            return null;
        }
        $prices = [];
        foreach ($raw as $value) {
            $price = self::price($value);
            if ($price === null) {
                return null;
            }
            $prices[] = $price;
        }

        return $prices;
    }

    private static function parseExperiment(mixed $e, int $regions, bool $scoped): ?Experiment
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
            $weight = self::weight(Util::prop($v, 'weight'));
            if (!is_string($vid) || !Util::isId($vid) || $price === null || $weight === null) {
                return null;
            }
            $regional = self::parseRegional(Util::prop($v, 'regional'), $regions);
            if ($regional === null) {
                return null;
            }
            $list[] = new Variant($vid, $price, $weight, self::httpsUrl(Util::prop($v, 'checkoutUrl')), $regional);
        }
        $splitOf = self::parseSplits(Util::prop($e, 'splits'), count($list));
        $scope = $scoped ? self::parseScope(Util::prop($e, 'countries')) : null;
        if ($splitOf === null || ($scoped && $scope === null)) {
            return null;
        }

        return new Experiment($id, $salt, $list, $scope, $splitOf);
    }

    /** @return array<string, list<int>>|null */
    private static function parseSplits(mixed $raw, int $variants): ?array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw) || count($raw) > self::MAX_SPLITS) {
            return null;
        }
        $splitOf = [];
        foreach ($raw as $s) {
            if (!$s instanceof \stdClass) {
                return null;
            }
            $countries = self::parseCountries(Util::prop($s, 'countries'));
            $weights = self::parseWeights(Util::prop($s, 'weights'), $variants);
            if ($countries === null || $weights === null) {
                return null;
            }
            foreach ($countries as $country) {
                if (isset($splitOf[$country])) {
                    return null;
                }
                $splitOf[$country] = $weights;
            }
        }

        return $splitOf;
    }

    /** @return list<int>|null */
    private static function parseWeights(mixed $raw, int $variants): ?array
    {
        if (!is_array($raw) || count($raw) !== $variants) {
            return null;
        }
        $weights = [];
        foreach ($raw as $value) {
            $weight = self::weight($value);
            if ($weight === null) {
                return null;
            }
            $weights[] = $weight;
        }

        return $weights;
    }

    /** @return array<string, true>|null */
    private static function parseScope(mixed $raw): ?array
    {
        $countries = self::parseCountries($raw, self::MAX_SCOPE);
        if ($countries === null) {
            return null;
        }
        $scope = array_fill_keys($countries, true);

        return count($scope) === count($countries) ? $scope : null;
    }

    private static function price(mixed $v): ?int
    {
        $n = Util::safeInt($v);

        return $n !== null && $n > 0 ? $n : null;
    }

    private static function weight(mixed $v): ?int
    {
        $n = Util::safeInt($v);

        return $n !== null && $n >= 0 ? $n : null;
    }

    private static function httpsUrl(mixed $v): ?string
    {
        if (!is_string($v) || !Util::isUtf8($v)) {
            return null;
        }

        return preg_match('/^https:\/\/[^' . Util::jsSpace() . ']+$/iuD', $v) === 1 ? $v : null;
    }

    private static function headerName(mixed $v): ?string
    {
        return is_string($v) && preg_match(self::HEADER_NAME_RE, $v) === 1 ? strtolower($v) : null;
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
