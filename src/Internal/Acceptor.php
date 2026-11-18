<?php

declare(strict_types=1);

namespace Leverprice\Internal;

/** @internal */
final class Acceptor
{
    private ?Config $config = null;
    private ?SigningKey $signingKey = null;

    public function __construct(
        public readonly TrustChain $trust,
        private readonly string $fingerprint,
    ) {}

    public static function fingerprint(string $secretKey): string
    {
        return substr(hash('sha256', $secretKey), 0, 16);
    }

    public function config(): ?Config
    {
        return $this->config;
    }

    public function trusted(): bool
    {
        return $this->signingKey === null || $this->trust->isTrusted($this->signingKey);
    }

    /** @throws ConfigError */
    public function accept(mixed $envelope, int $now): VerifiedConfig
    {
        $verified = $this->trust->verify($envelope, $now);
        $config = $verified->config;
        if (!in_array($this->fingerprint, $config->keyFingerprints, true)) {
            throw new ConfigError(ConfigError::WRONG_KEY, 'Leverprice config: not issued for this API key; refusing');
        }
        $current = $this->config;
        if ($current !== null && $config->projectId !== $current->projectId) {
            throw new ConfigError(ConfigError::WRONG_PROJECT, sprintf(
                'Leverprice config: project changed from %s to %s; refusing',
                $current->projectId,
                $config->projectId,
            ));
        }
        if ($current !== null && $config->version < $current->version) {
            throw new ConfigError(ConfigError::ROLLBACK, sprintf(
                'Leverprice config: refusing to roll back from version %d to %d',
                $current->version,
                $config->version,
            ));
        }
        $this->config = $config;
        $this->signingKey = $verified->signingKey;

        return $verified;
    }
}
