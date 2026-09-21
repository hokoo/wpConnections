<?php

namespace iTRON\wpConnections\Internal;

use DateTimeImmutable;
use InvalidArgumentException;

final class DeletedPostRepairPolicy
{
    private const LEASE_SECONDS = 600;
    private const RESOLVED_RETENTION_SECONDS = 2592000;
    private const RETRY_DELAYS_SECONDS = [
        60,
        300,
        900,
        3600,
        10800,
        21600,
        43200,
        86400,
    ];

    private DeletedPostRepairClockInterface $clock;

    public function __construct(DeletedPostRepairClockInterface $clock)
    {
        $this->clock = $clock;
    }

    public function utcNow(): DateTimeImmutable
    {
        $now = $this->clock->utcNow();
        $this->assertUtc($now);

        return $now;
    }

    public function monotonicSeconds(): float
    {
        $reading = $this->clock->monotonicSeconds();
        if (! is_finite($reading) || 0 > $reading) {
            throw new InvalidArgumentException('Repair monotonic clock reading is invalid.');
        }

        return $reading;
    }

    public function leaseExpiresAt(DateTimeImmutable $now): DateTimeImmutable
    {
        $this->assertUtc($now);

        return $this->addSeconds($now, self::LEASE_SECONDS);
    }

    public function nextAttemptAt(DateTimeImmutable $now, int $delayIndex): ?DateTimeImmutable
    {
        $this->assertUtc($now);
        if (0 > $delayIndex || count(self::RETRY_DELAYS_SECONDS) < $delayIndex) {
            throw new InvalidArgumentException('Repair retry delay index must be between 0 and 8.');
        }
        if (count(self::RETRY_DELAYS_SECONDS) === $delayIndex) {
            return null;
        }

        return $this->addSeconds($now, self::RETRY_DELAYS_SECONDS[$delayIndex]);
    }

    public function isResolvedPurgeable(DateTimeImmutable $resolvedAt, DateTimeImmutable $now): bool
    {
        $this->assertUtc($resolvedAt);
        $this->assertUtc($now);

        return $resolvedAt->getTimestamp() < $now->getTimestamp() - self::RESOLVED_RETENTION_SECONDS;
    }

    public function resolvedPurgeAt(DateTimeImmutable $resolvedAt): DateTimeImmutable
    {
        $this->assertUtc($resolvedAt);

        return $this->addSeconds($resolvedAt, self::RESOLVED_RETENTION_SECONDS + 1);
    }

    public function resolvedRetentionCutoff(DateTimeImmutable $now): DateTimeImmutable
    {
        $this->assertUtc($now);

        return $this->addSeconds($now, -self::RESOLVED_RETENTION_SECONDS);
    }

    private function addSeconds(DateTimeImmutable $time, int $seconds): DateTimeImmutable
    {
        $result = $time->setTimestamp($time->getTimestamp() + $seconds);
        $this->assertUtc($result);

        return $result;
    }

    private function assertUtc(DateTimeImmutable $time): void
    {
        if ('UTC' !== $time->getTimezone()->getName() || 0 !== $time->getOffset()) {
            throw new InvalidArgumentException('Deleted-post repair policy timestamps must be UTC.');
        }
        $year = (int) $time->format('Y');
        if (1000 > $year || 9999 < $year) {
            throw new InvalidArgumentException('Deleted-post repair policy timestamps must fit the database range.');
        }
    }
}
