<?php

namespace iTRON\wpConnections\Internal;

use InvalidArgumentException;

final class DeletedPostRepairClaimResult
{
    private const OUTCOMES = [
        'acquired',
        'adapter_mismatch',
        'already_running',
        'attempts_exhausted',
        'not_due',
        'not_found',
        'resolved',
        'unavailable',
    ];

    private string $outcome;
    private ?DeletedPostRepairLease $lease;

    public function __construct(string $outcome, ?DeletedPostRepairLease $lease = null)
    {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException('Unknown deleted-post repair claim outcome.');
        }

        if (('acquired' === $outcome) !== (null !== $lease)) {
            throw new InvalidArgumentException('Only an acquired repair claim may contain a lease.');
        }

        $this->outcome = $outcome;
        $this->lease = $lease;
    }

    public function getOutcome(): string
    {
        return $this->outcome;
    }

    public function getLease(): ?DeletedPostRepairLease
    {
        return $this->lease;
    }
}
