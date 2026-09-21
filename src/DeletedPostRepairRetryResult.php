<?php

namespace iTRON\wpConnections;

use InvalidArgumentException;

final class DeletedPostRepairRetryResult
{
    private const OUTCOMES = [
        'resolved',
        'already_running',
        'not_found_or_foreign',
        'retry_failed',
    ];

    private string $repairKey;
    private string $outcome;
    private bool $cleanupAttempted;
    private ?DeletedPostRepairView $repair;

    private function __construct(
        string $repairKey,
        string $outcome,
        bool $cleanupAttempted,
        ?DeletedPostRepairView $repair
    ) {
        if (! preg_match('/^[a-f0-9]{64}$/D', $repairKey)) {
            throw new InvalidArgumentException('Invalid deleted-post repair result key.');
        }
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException('Unknown deleted-post repair retry outcome.');
        }
        if ('not_found_or_foreign' === $outcome && null !== $repair) {
            throw new InvalidArgumentException('A missing repair result cannot contain a repair view.');
        }

        $this->repairKey = $repairKey;
        $this->outcome = $outcome;
        $this->cleanupAttempted = $cleanupAttempted;
        $this->repair = $repair;
    }

    /**
     * @internal Library-owned construction path; not part of the compatibility surface.
     */
    public static function createInternal(
        string $repairKey,
        string $outcome,
        bool $cleanupAttempted,
        ?DeletedPostRepairView $repair
    ): self {
        return new self($repairKey, $outcome, $cleanupAttempted, $repair);
    }

    public function getRepairKey(): string
    {
        return $this->repairKey;
    }

    public function getOutcome(): string
    {
        return $this->outcome;
    }

    public function wasCleanupAttempted(): bool
    {
        return $this->cleanupAttempted;
    }

    public function getRepair(): ?DeletedPostRepairView
    {
        return $this->repair;
    }
}
