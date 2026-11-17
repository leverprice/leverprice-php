<?php

declare(strict_types=1);

namespace Leverprice;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    public function sleep(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
