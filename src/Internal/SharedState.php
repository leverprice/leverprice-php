<?php

declare(strict_types=1);

namespace Leverprice\Internal;

/** @internal */
final class SharedState
{
    private const FORMAT = 1;

    public ?string $envelope = null;
    public ?string $etag = null;
    public int $fetchedAt = 0;
    public int $configVersion = -1;
    public ?string $projectId = null;
    /** @var array{payload: string, signature: string, rootKeyId: string}|null */
    public ?array $keyset = null;
    public int $keysetVersion = 0;
    public int $serverVersion = 0;
    public int $catchUpAt = 0;
    public int $failures = 0;
    public int $retryAt = 0;
    public int $leaseSince = 0;
    public string $leaseOwner = '';
    public int $eventFailures = 0;
    public int $eventsRetryAt = 0;
    public int $outboxSize = 0;
    public bool $outboxTouched = false;

    public function encode(string $macKey): string
    {
        $json = Util::jsonEncode([
            'f' => self::FORMAT,
            'envelope' => $this->envelope,
            'etag' => $this->etag,
            'fetchedAt' => $this->fetchedAt,
            'configVersion' => $this->configVersion,
            'projectId' => $this->projectId,
            'keyset' => $this->keyset,
            'keysetVersion' => $this->keysetVersion,
            'serverVersion' => $this->serverVersion,
            'catchUpAt' => $this->catchUpAt,
            'failures' => $this->failures,
            'retryAt' => $this->retryAt,
            'leaseSince' => $this->leaseSince,
            'leaseOwner' => $this->leaseOwner,
            'eventFailures' => $this->eventFailures,
            'eventsRetryAt' => $this->eventsRetryAt,
            'outboxSize' => $this->outboxSize,
        ]);

        return hash_hmac('sha256', $json, $macKey) . "\n" . $json;
    }

    public static function decode(?string $blob, string $macKey): ?self
    {
        if ($blob === null) {
            return null;
        }
        $newline = strpos($blob, "\n");
        if ($newline !== 64) {
            return null;
        }
        $json = substr($blob, 65);
        if (!hash_equals(hash_hmac('sha256', $json, $macKey), substr($blob, 0, 64))) {
            return null;
        }
        try {
            $raw = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($raw) || ($raw['f'] ?? null) !== self::FORMAT) {
            return null;
        }
        $s = new self();
        $s->envelope = self::str($raw['envelope'] ?? null);
        $s->etag = self::str($raw['etag'] ?? null);
        $s->fetchedAt = self::int($raw['fetchedAt'] ?? 0);
        $s->configVersion = self::int($raw['configVersion'] ?? -1);
        $s->projectId = self::str($raw['projectId'] ?? null);
        $s->keyset = self::keyset($raw['keyset'] ?? null);
        $s->keysetVersion = self::int($raw['keysetVersion'] ?? 0);
        $s->serverVersion = self::int($raw['serverVersion'] ?? 0);
        $s->catchUpAt = self::int($raw['catchUpAt'] ?? 0);
        $s->failures = self::int($raw['failures'] ?? 0);
        $s->retryAt = self::int($raw['retryAt'] ?? 0);
        $s->leaseSince = self::int($raw['leaseSince'] ?? 0);
        $s->leaseOwner = self::str($raw['leaseOwner'] ?? null) ?? '';
        $s->eventFailures = self::int($raw['eventFailures'] ?? 0);
        $s->eventsRetryAt = self::int($raw['eventsRetryAt'] ?? 0);
        $s->outboxSize = self::int($raw['outboxSize'] ?? 0);

        return $s;
    }

    public function merge(?self $stored, string $owner): self
    {
        if ($stored === null) {
            return $this;
        }
        $out = clone $this;
        $sameProject = $this->projectId === null || $stored->projectId === $this->projectId;
        if ($stored->envelope !== null && $sameProject && $stored->configVersion > $this->configVersion) {
            $out->envelope = $stored->envelope;
            $out->etag = $stored->etag;
            $out->fetchedAt = $stored->fetchedAt;
            $out->configVersion = $stored->configVersion;
            $out->projectId = $stored->projectId;
        } elseif ($stored->envelope === $this->envelope) {
            $out->fetchedAt = max($this->fetchedAt, $stored->fetchedAt);
            $out->etag = $this->etag ?? $stored->etag;
        }
        if ($stored->keysetVersion > $this->keysetVersion) {
            $out->keyset = $stored->keyset;
            $out->keysetVersion = $stored->keysetVersion;
        }
        $out->outboxSize = $this->outboxTouched ? $this->outboxSize : $stored->outboxSize;
        $out->serverVersion = max($this->serverVersion, $stored->serverVersion);
        $out->catchUpAt = max($this->catchUpAt, $stored->catchUpAt);
        if ($this->leaseOwner === $owner && $this->leaseSince > 0) {
            return $out;
        }
        if ($this->leaseOwner === $owner && $stored->leaseOwner === $owner) {
            $out->leaseSince = 0;
            $out->leaseOwner = '';

            return $out;
        }
        $out->leaseSince = $stored->leaseSince;
        $out->leaseOwner = $stored->leaseOwner;

        return $out;
    }

    private static function str(mixed $v): ?string
    {
        return is_string($v) ? $v : null;
    }

    private static function int(mixed $v): int
    {
        return is_int($v) ? $v : 0;
    }

    /** @return array{payload: string, signature: string, rootKeyId: string}|null */
    private static function keyset(mixed $v): ?array
    {
        if (!is_array($v)) {
            return null;
        }
        $payload = $v['payload'] ?? null;
        $signature = $v['signature'] ?? null;
        $rootKeyId = $v['rootKeyId'] ?? null;
        if (!is_string($payload) || !is_string($signature) || !is_string($rootKeyId)) {
            return null;
        }

        return ['payload' => $payload, 'signature' => $signature, 'rootKeyId' => $rootKeyId];
    }
}
