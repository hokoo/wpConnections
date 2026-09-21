<?php

namespace iTRON\wpConnections\Internal;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * @internal Native site-local WP-Cron single-event adapter.
 */
final class WordPressDeletedPostRepairScheduler implements DeletedPostRepairSchedulerInterface
{
    public const EVENT_HOOK = 'wpConnections/deletedPostRepair/run';

    private const EVENT_ARGUMENTS = [];

    public function nextWakeupAt(): ?DateTimeImmutable
    {
        $timestamp = wp_next_scheduled(self::EVENT_HOOK, self::EVENT_ARGUMENTS);
        if (false === $timestamp) {
            return null;
        }
        if (! is_int($timestamp) || 0 >= $timestamp) {
            throw new DeletedPostRepairSchedulerFailure('read the next wake-up');
        }

        return ( new DateTimeImmutable('@' . $timestamp) )->setTimezone(new DateTimeZone('UTC'));
    }

    public function scheduleWakeup(DateTimeImmutable $at): void
    {
        $this->assertUtc($at);
        $result = wp_schedule_single_event(
            $at->getTimestamp(),
            self::EVENT_HOOK,
            self::EVENT_ARGUMENTS,
            true
        );
        if (true !== $result) {
            throw new DeletedPostRepairSchedulerFailure('schedule a wake-up');
        }
    }

    public function unscheduleWakeup(DateTimeImmutable $at): void
    {
        $this->assertUtc($at);
        $result = wp_unschedule_event(
            $at->getTimestamp(),
            self::EVENT_HOOK,
            self::EVENT_ARGUMENTS,
            true
        );
        if (true !== $result) {
            throw new DeletedPostRepairSchedulerFailure('remove a stale wake-up');
        }
    }

    public function isAutomaticDispatchAvailable(): bool
    {
        return ! (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON);
    }

    private function assertUtc(DateTimeImmutable $at): void
    {
        if (
            'UTC' !== $at->getTimezone()->getName() ||
            0 !== $at->getOffset() ||
            0 >= $at->getTimestamp()
        ) {
            throw new InvalidArgumentException('Repair scheduler timestamps must be positive UTC values.');
        }
    }
}
