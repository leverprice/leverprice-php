<?php

declare(strict_types=1);

namespace Leverprice\Tests\Support;

use Leverprice\Clock;

final class FakeClock implements Clock
{
    /** @var list<int> */
    public array $sleeps = [];

    public function __construct(public int $now) {}

    public function now(): int
    {
        return $this->now;
    }

    public function sleep(int $milliseconds): void
    {
        $this->sleeps[] = $milliseconds;
        $this->now += max(0, $milliseconds);
    }

    public function advance(int $milliseconds): void
    {
        $this->now += $milliseconds;
    }
}
