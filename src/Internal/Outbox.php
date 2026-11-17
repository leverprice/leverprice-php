<?php

declare(strict_types=1);

namespace Leverprice\Internal;

use Leverprice\State\LockingStateStore;
use Leverprice\State\StateStore;

/**
 * @internal
 *
 * @phpstan-type WireEvent array<string, mixed>
 * @phpstan-type Record array{events: list<WireEvent>, leaseSince: int, leaseOwner: string}
 */
final class Outbox
{
    public const MAX_EVENTS = 1000;
    public const MAX_AGE_MS = 5184000000;
    public const LEASE_MS = 45000;
    private const FORMAT = 'outbox.v1';

    public int $size = 0;

    /** @var list<string> */
    private array $problems = [];
    private bool $unreadableReported = false;

    public function __construct(
        private readonly StateStore $store,
        private readonly string $key,
        private readonly string $macKey,
        private readonly string $owner,
    ) {}

    /** @param WireEvent $event */
    public static function identity(array $event): string
    {
        return Util::jsonEncode($event);
    }

    /** @return list<WireEvent>|null null when the outbox could not be claimed */
    public function claim(int $now): ?array
    {
        $claimed = null;
        $saved = $this->update($now, function (array $record) use ($now, &$claimed): ?array {
            $leased = $record['leaseOwner'] !== ''
                && $record['leaseOwner'] !== $this->owner
                && $now - $record['leaseSince'] < self::LEASE_MS;
            if ($leased) {
                return null;
            }
            $claimed = $record['events'];
            if ($claimed === []) {
                return null;
            }
            $record['leaseSince'] = $now;
            $record['leaseOwner'] = $this->owner;

            return $record;
        });

        return $saved ? $claimed : null;
    }

    /**
     * @param array<string, true> $done
     * @param list<WireEvent> $pending
     */
    public function settle(array $done, array $pending, bool $release, int $now): bool
    {
        return $this->update($now, function (array $record) use ($done, $pending, $release): array {
            $events = [];
            $seen = [];
            foreach ([...$record['events'], ...$pending] as $event) {
                $id = self::identity($event);
                if (isset($done[$id]) || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $events[] = $event;
            }
            $record['events'] = $events;
            if ($release && $record['leaseOwner'] === $this->owner) {
                $record['leaseSince'] = 0;
                $record['leaseOwner'] = '';
            }

            return $record;
        });
    }

    /** @return list<string> */
    public function takeProblems(): array
    {
        $problems = $this->problems;
        $this->problems = [];

        return $problems;
    }

    /** @param callable(Record): ?Record $change */
    private function update(int $now, callable $change): bool
    {
        $mutate = function (?string $blob) use ($now, $change): ?string {
            [$record, $dirty] = $this->decode($blob);
            $dirty = $this->evictExpired($record, $now) || $dirty;
            $next = $change($record);
            if ($next === null && !$dirty) {
                $this->size = count($record['events']);

                return null;
            }
            $next ??= $record;
            $this->enforceCap($next);
            $this->size = count($next['events']);

            return $this->encode($next);
        };
        try {
            if ($this->store instanceof LockingStateStore) {
                return $this->store->update($this->key, $mutate);
            }
            $value = $mutate($this->store->read($this->key));

            return $value === null || $this->store->write($this->key, $value);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{Record, bool} */
    private function decode(?string $blob): array
    {
        $empty = ['events' => [], 'leaseSince' => 0, 'leaseOwner' => ''];
        if ($blob === null) {
            return [$empty, false];
        }
        $raw = null;
        if (strpos($blob, "\n") === 64) {
            $json = substr($blob, 65);
            if (hash_equals(hash_hmac('sha256', $json, $this->macKey), substr($blob, 0, 64))) {
                try {
                    $raw = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $raw = null;
                }
            }
        }
        if (!is_array($raw) || ($raw['format'] ?? null) !== self::FORMAT || !is_array($raw['events'] ?? null)) {
            if (!$this->unreadableReported) {
                $this->unreadableReported = true;
                $this->problems[] = 'Leverprice: discarded an unreadable event outbox';
            }

            return [$empty, true];
        }
        $events = [];
        foreach ($raw['events'] as $event) {
            if (!is_array($event) || array_is_list($event)) {
                continue;
            }
            /** @var WireEvent $event */
            $type = $event['type'] ?? null;
            if (($type === 'purchase' || $type === 'refund') && Wire::eventProblem($event) === null) {
                $events[] = $event;
            }
        }
        $leaseSince = $raw['leaseSince'] ?? 0;
        $leaseOwner = $raw['leaseOwner'] ?? '';

        return [[
            'events' => $events,
            'leaseSince' => is_int($leaseSince) ? $leaseSince : 0,
            'leaseOwner' => is_string($leaseOwner) ? $leaseOwner : '',
        ], false];
    }

    /** @param Record $record */
    private function encode(array $record): string
    {
        $json = Util::jsonEncode(['format' => self::FORMAT] + $record);

        return hash_hmac('sha256', $json, $this->macKey) . "\n" . $json;
    }

    /** @param Record $record */
    private function evictExpired(array &$record, int $now): bool
    {
        $kept = array_values(array_filter(
            $record['events'],
            static fn(array $e): bool => is_int($e['timestamp'] ?? null) && $now - $e['timestamp'] <= self::MAX_AGE_MS,
        ));
        $expired = count($record['events']) - count($kept);
        if ($expired === 0) {
            return false;
        }
        $record['events'] = $kept;
        $this->problems[] = sprintf('Leverprice: dropped %d purchase/refund event(s) older than 60 days from the outbox', $expired);

        return true;
    }

    /** @param Record $record */
    private function enforceCap(array &$record): void
    {
        while (count($record['events']) > self::MAX_EVENTS) {
            $dropped = array_shift($record['events']);
            $this->problems[] = sprintf(
                'Leverprice: event outbox full, dropped %s %s',
                Util::describe($dropped['type'] ?? null),
                Util::describe($dropped['orderId'] ?? null),
            );
        }
    }
}
