<?php

namespace iTRON\wpConnections\Internal;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class SystemDeletedPostRepairClock implements DeletedPostRepairClockInterface
{
    public function utcNow(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function monotonicSeconds(): float
    {
        $reading = hrtime(true);
        if (false === $reading) {
            throw new RuntimeException('A monotonic repair clock is unavailable.');
        }

        $seconds = $reading / 1000000000;
        if (! is_finite($seconds) || 0 > $seconds) {
            throw new RuntimeException('A monotonic repair clock is unavailable.');
        }

        return $seconds;
    }
}
