<?php

namespace iTRON\wpConnections\Internal;

use DateTimeImmutable;

/**
 * @internal Site-local single-event wake-up transport.
 */
interface DeletedPostRepairSchedulerInterface
{
    public function nextWakeupAt(): ?DateTimeImmutable;

    public function scheduleWakeup(DateTimeImmutable $at): void;

    public function unscheduleWakeup(DateTimeImmutable $at): void;

    public function isAutomaticDispatchAvailable(): bool;
}
