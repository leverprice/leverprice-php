<?php

declare(strict_types=1);

namespace Leverprice\Internal;

use Leverprice\LeverpriceException;

/** @internal */
final class TrustChain
{
    public const ROOT_KEY_PLACEHOLDER = 'REPLACE_WITH_ROOT_PUBLIC_KEY';
    private const VERIFIED_CACHE_SIZE = 8;

    private ?Keyset $newest = null;
    private int $floor = 0;

    /** @var array<string, Keyset> */
    private array $verified = [];

    /** @param array<string, string> $rootKeys */
    public function __construct(
        private readonly array $rootKeys,
        private int $minKeysetVersion,
    ) {}

    public function setMinKeysetVersion(int $version): void
    {
        $this->minKeysetVersion = $version;
    }

    public function keysetVersion(): int
    {
        return max($this->floor, $this->newest->version ?? 0);
    }

    /** @return array{payload: string, signature: string, rootKeyId: string}|null */
    public function newestSigned(): ?array
    {
        return $this->newest?->signed;
    }

    public function raiseFloor(int $version): void
    {
        $this->floor = max($this->floor, $version);
    }

    public function restore(mixed $signedKeyset, int $now): void
    {
        $this->verifyKeyset($signedKeyset, $now);
    }

    public function isTrusted(SigningKey $key): bool
    {
        if ($this->newest === null) {
            return true;
        }
        foreach ($this->newest->keys as $k) {
            if ($k->id === $key->id && $k->publicKey === $key->publicKey) {
                return true;
            }
        }

        return false;
    }

    public function verify(mixed $envelope, int $now): VerifiedConfig
    {
        if (!$envelope instanceof \stdClass) {
            throw new LeverpriceException('Leverprice config: response is not a signed config');
        }
        $payload = Util::prop($envelope, 'payload');
        $signature = Util::prop($envelope, 'signature');
        $keyId = Util::prop($envelope, 'keyId');
        if (!is_string($payload) || !is_string($signature) || !is_string($keyId)) {
            throw new LeverpriceException('Leverprice config: response is not a signed config');
        }
        $keyset = $this->verifyKeyset(Util::prop($envelope, 'keyset'), $now);
        $key = null;
        foreach ($keyset->keys as $k) {
            if ($k->id === $keyId) {
                $key = $k;
                break;
            }
        }
        if ($key === null) {
            throw new LeverpriceException(sprintf('Leverprice config: signing key "%s" is not in keyset v%d', $keyId, $keyset->version));
        }
        if (!Ed25519::verify($key->publicKey, $signature, $payload)) {
            throw new LeverpriceException(sprintf('Leverprice config: bad signature (key "%s")', $keyId));
        }
        [$config, $dropped] = Wire::parseConfig($payload);
        if ($now >= $config->expiresAt) {
            throw new LeverpriceException(sprintf(
                'Leverprice config v%d: expired at %s',
                $config->version,
                Util::isoTime($config->expiresAt),
            ));
        }

        return new VerifiedConfig($config, new SigningKey($key->id, $key->publicKey), $dropped);
    }

    private function verifyKeyset(mixed $signed, int $now): Keyset
    {
        $payload = $signed instanceof \stdClass ? Util::prop($signed, 'payload') : null;
        $signature = $signed instanceof \stdClass ? Util::prop($signed, 'signature') : null;
        $rootKeyId = $signed instanceof \stdClass ? Util::prop($signed, 'rootKeyId') : null;
        if (!is_string($payload) || !is_string($signature) || !is_string($rootKeyId)) {
            throw new LeverpriceException('Leverprice keyset: missing or malformed');
        }
        $keyset = $this->verified[$payload] ?? null;
        if ($keyset === null) {
            $root = array_key_exists($rootKeyId, $this->rootKeys) ? $this->rootKeys[$rootKeyId] : null;
            if ($root === null) {
                throw new LeverpriceException(sprintf('Leverprice keyset: root key "%s" is not trusted by this SDK', $rootKeyId));
            }
            if ($root === self::ROOT_KEY_PLACEHOLDER) {
                throw new LeverpriceException(
                    'Leverprice: this SDK build has no root public key (ROOT_PUBLIC_KEYS is a placeholder); pass trustedRootKeys',
                );
            }
            if (!Ed25519::verify($root, $signature, $payload)) {
                throw new LeverpriceException(sprintf('Leverprice keyset: signature does not match root key "%s"', $rootKeyId));
            }
            $keyset = Wire::parseKeyset(['payload' => $payload, 'signature' => $signature, 'rootKeyId' => $rootKeyId]);
            if (count($this->verified) >= self::VERIFIED_CACHE_SIZE) {
                $this->verified = [];
            }
            $this->verified[$payload] = $keyset;
        }
        if ($keyset->expiresAt !== null && $now >= $keyset->expiresAt) {
            throw new LeverpriceException(sprintf('Leverprice keyset v%d: expired', $keyset->version));
        }
        if ($keyset->version < $this->minKeysetVersion) {
            throw new LeverpriceException(sprintf(
                'Leverprice keyset v%d: below the minimum version %d',
                $keyset->version,
                $this->minKeysetVersion,
            ));
        }
        $seen = $this->keysetVersion();
        if ($keyset->version < $seen) {
            throw new LeverpriceException(sprintf(
                'Leverprice keyset v%d: older than v%d already seen (revoked keys cannot come back)',
                $keyset->version,
                $seen,
            ));
        }
        if ($this->newest === null || $keyset->version > $this->newest->version) {
            $this->newest = $keyset;
        }

        return $keyset;
    }
}
