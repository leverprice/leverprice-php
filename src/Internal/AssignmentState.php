<?php

declare(strict_types=1);

namespace Leverprice\Internal;

/** @internal */
final class AssignmentState
{
    public const MAX_EXPERIMENTS = 10;

    /**
     * @param list<array{string, string}> $a
     * @param list<string> $x
     * @param ?string $c the country whose price the visitor holds
     * @param ?int $t when that country was set, in seconds since the epoch
     */
    public function __construct(
        public string $v,
        public array $a = [],
        public array $x = [],
        public ?string $c = null,
        public ?int $t = null,
    ) {}

    public function variantFor(string $experimentId): ?string
    {
        foreach ($this->a as [$exp, $variant]) {
            if ($exp === $experimentId) {
                return $variant;
            }
        }

        return null;
    }

    public function assign(string $experimentId, string $variantId): void
    {
        $pairs = [[$experimentId, $variantId]];
        foreach ($this->a as $pair) {
            if ($pair[0] !== $experimentId) {
                $pairs[] = $pair;
            }
        }
        $this->a = array_slice($pairs, 0, self::MAX_EXPERIMENTS);
        $this->x = array_values(array_filter($this->x, fn(string $id): bool => $this->variantFor($id) !== null));
    }

    public function markExposed(string $experimentId): bool
    {
        if (in_array($experimentId, $this->x, true)) {
            return false;
        }
        $x = array_filter([$experimentId, ...$this->x], fn(string $id): bool => $this->variantFor($id) !== null);
        $this->x = array_slice(array_values($x), 0, self::MAX_EXPERIMENTS);

        return true;
    }

    public function toJson(): string
    {
        $a = [];
        foreach ($this->a as [$exp, $variant]) {
            $a[] = Util::jsonEncode($exp) . ':' . Util::jsonEncode($variant);
        }

        $held = $this->c !== null && $this->t !== null
            ? ',"c":' . Util::jsonEncode($this->c) . ',"t":' . $this->t
            : '';

        return '{"v":' . Util::jsonEncode($this->v) . ',"a":{' . implode(',', $a) . '},"x":' . Util::jsonEncode($this->x) . $held . '}';
    }
}
