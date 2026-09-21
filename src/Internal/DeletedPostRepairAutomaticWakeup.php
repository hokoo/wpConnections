<?php

namespace iTRON\wpConnections\Internal;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * @internal Attributable next automatic ledger deadline.
 */
final class DeletedPostRepairAutomaticWakeup
{
    private string $repairKey;
    private DateTimeImmutable $at;

    public function __construct(string $repairKey, DateTimeImmutable $at)
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $repairKey)) {
            throw new InvalidArgumentException('Automatic repair wake-up key is invalid.');
        }
        if ('UTC' !== $at->getTimezone()->getName() || 0 !== $at->getOffset()) {
            throw new InvalidArgumentException('Automatic repair wake-up must use UTC.');
        }

        $this->repairKey = $repairKey;
        $this->at = $at;
    }

    public function getRepairKey(): string
    {
        return $this->repairKey;
    }

    public function getAt(): DateTimeImmutable
    {
        return $this->at;
    }
}
