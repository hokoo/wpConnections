<?php

namespace iTRON\wpConnections\Tests;

use DateTimeImmutable;
use DateTimeZone;
use iTRON\wpConnections\Internal\DeletedPostRepairClockInterface;
use iTRON\wpConnections\Internal\DeletedPostRepairPolicy;
use iTRON\wpConnections\Internal\SystemDeletedPostRepairClock;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DeletedPostRepairPolicyTest extends TestCase
{
    public function test_system_clock_returns_utc_and_non_decreasing_monotonic_seconds(): void
    {
        $clock = new SystemDeletedPostRepairClock();
        $first = $clock->monotonicSeconds();
        $second = $clock->monotonicSeconds();

        self::assertSame('UTC', $clock->utcNow()->getTimezone()->getName());
        self::assertTrue(is_finite($first));
        self::assertTrue(is_finite($second));
        self::assertGreaterThanOrEqual($first, $second);
    }

    public function test_policy_clock_accepts_deterministic_forward_and_backward_wall_movement(): void
    {
        $clock = $this->clock($this->utc('2026-09-14 10:00:00'));
        $policy = new DeletedPostRepairPolicy($clock);

        self::assertSame('2026-09-14 10:00:00', $policy->utcNow()->format('Y-m-d H:i:s'));

        $clock->setNow($this->utc('2026-09-15 10:00:00'));
        self::assertSame('2026-09-15 10:00:00', $policy->utcNow()->format('Y-m-d H:i:s'));

        $clock->setNow($this->utc('2026-09-13 10:00:00'));
        self::assertSame('2026-09-13 10:00:00', $policy->utcNow()->format('Y-m-d H:i:s'));
        self::assertSame(100.0, $clock->monotonicSeconds());
    }

    public function test_non_utc_clock_value_is_rejected(): void
    {
        $clock = $this->clock(
            new DateTimeImmutable('2026-09-14 10:00:00', new DateTimeZone('Asia/Tbilisi'))
        );

        $this->expectException(InvalidArgumentException::class);
        ( new DeletedPostRepairPolicy($clock) )->utcNow();
    }

    public function test_zero_offset_named_timezone_is_not_accepted_as_utc(): void
    {
        $london_before_dst = new DateTimeImmutable(
            '2026-03-29 00:55:00',
            new DateTimeZone('Europe/London')
        );
        self::assertSame(0, $london_before_dst->getOffset());

        $this->expectException(InvalidArgumentException::class);
        $this->policy()->leaseExpiresAt($london_before_dst);
    }

    public function test_lease_deadline_is_exactly_ten_minutes_across_day_boundary(): void
    {
        $policy = $this->policy();

        self::assertSame(
            '2027-01-01 00:05:00',
            $policy->leaseExpiresAt($this->utc('2026-12-31 23:55:00'))->format('Y-m-d H:i:s')
        );
    }

    /**
     * @dataProvider retry_delay_provider
     */
    public function test_retry_delay_table(int $delay_index, ?int $expected_seconds): void
    {
        $now = $this->utc('2026-09-14 10:00:00');
        $next = $this->policy()->nextAttemptAt($now, $delay_index);

        if (null === $expected_seconds) {
            self::assertNull($next);
            return;
        }

        self::assertInstanceOf(DateTimeImmutable::class, $next);
        self::assertSame($now->getTimestamp() + $expected_seconds, $next->getTimestamp());
        self::assertSame('UTC', $next->getTimezone()->getName());
    }

    public function retry_delay_provider(): array
    {
        return [
            'one minute'      => [ 0, 60 ],
            'five minutes'    => [ 1, 300 ],
            'fifteen minutes' => [ 2, 900 ],
            'one hour'        => [ 3, 3600 ],
            'three hours'     => [ 4, 10800 ],
            'six hours'       => [ 5, 21600 ],
            'twelve hours'    => [ 6, 43200 ],
            'twenty-four hours' => [ 7, 86400 ],
            'no ninth delay'  => [ 8, null ],
        ];
    }

    /**
     * @dataProvider invalid_retry_index_provider
     */
    public function test_retry_index_outside_policy_range_is_rejected(int $delay_index): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy()->nextAttemptAt($this->utc('2026-09-14 10:00:00'), $delay_index);
    }

    public function invalid_retry_index_provider(): array
    {
        return [
            'negative' => [ -1 ],
            'too high' => [ 9 ],
        ];
    }

    public function test_resolved_retention_is_strictly_older_than_thirty_days(): void
    {
        $policy = $this->policy();
        $now = $this->utc('2026-10-14 10:00:00');

        self::assertFalse($policy->isResolvedPurgeable($now->modify('-2591999 seconds'), $now));
        self::assertFalse($policy->isResolvedPurgeable($now->modify('-2592000 seconds'), $now));
        self::assertTrue($policy->isResolvedPurgeable($now->modify('-2592001 seconds'), $now));
        self::assertFalse($policy->isResolvedPurgeable($now->modify('+1 second'), $now));
    }

    /**
     * @dataProvider non_utc_policy_argument_provider
     */
    public function test_non_utc_policy_arguments_are_rejected(string $operation): void
    {
        $policy = $this->policy();
        $utc = $this->utc('2026-09-14 10:00:00');
        $non_utc = new DateTimeImmutable('2026-09-14 10:00:00', new DateTimeZone('Asia/Tbilisi'));

        $this->expectException(InvalidArgumentException::class);
        if ('lease' === $operation) {
            $policy->leaseExpiresAt($non_utc);
        } elseif ('retry' === $operation) {
            $policy->nextAttemptAt($non_utc, 0);
        } elseif ('resolved' === $operation) {
            $policy->isResolvedPurgeable($non_utc, $utc);
        } else {
            $policy->isResolvedPurgeable($utc, $non_utc);
        }
    }

    public function non_utc_policy_argument_provider(): array
    {
        return [
            'lease base'         => [ 'lease' ],
            'retry base'         => [ 'retry' ],
            'resolved timestamp' => [ 'resolved' ],
            'comparison time'    => [ 'now' ],
        ];
    }

    private function policy(): DeletedPostRepairPolicy
    {
        return new DeletedPostRepairPolicy(
            $this->clock($this->utc('2026-09-14 10:00:00'))
        );
    }

    private function clock(DateTimeImmutable $now, float $monotonic_seconds = 100.0): object
    {
        return new class ($now, $monotonic_seconds) implements DeletedPostRepairClockInterface {
            private DateTimeImmutable $now;
            private float $monotonic_seconds;

            public function __construct(DateTimeImmutable $now, float $monotonic_seconds)
            {
                $this->now = $now;
                $this->monotonic_seconds = $monotonic_seconds;
            }

            public function utcNow(): DateTimeImmutable
            {
                return $this->now;
            }

            public function monotonicSeconds(): float
            {
                return $this->monotonic_seconds;
            }

            public function setNow(DateTimeImmutable $now): void
            {
                $this->now = $now;
            }
        };
    }

    private function utc(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }
}
