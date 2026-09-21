<?php

namespace iTRON\wpConnections\Internal;

use InvalidArgumentException;

/**
 * @internal The public operator service maps this result to its stable DTOs.
 */
final class DeletedPostRepairExecutionResult
{
    public const RESOLVED = 'resolved';
    public const RETRY_WAIT = 'retry_wait';
    public const NEEDS_ATTENTION = 'needs_attention';
    public const LEASE_LOST = 'lease_lost';

    private const OUTCOMES = [
        self::RESOLVED,
        self::RETRY_WAIT,
        self::NEEDS_ATTENTION,
        self::LEASE_LOST,
    ];

    private string $outcome;
    private bool $cleanupAttempted;

    public function __construct(string $outcome, bool $cleanupAttempted)
    {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException('Unknown deleted-post repair execution outcome.');
        }

        $this->outcome = $outcome;
        $this->cleanupAttempted = $cleanupAttempted;
    }

    public function getOutcome(): string
    {
        return $this->outcome;
    }

    public function wasCleanupAttempted(): bool
    {
        return $this->cleanupAttempted;
    }
}
