<?php

namespace iTRON\wpConnections\Internal;

use InvalidArgumentException;

/**
 * @internal One claim/execution observation from a bounded worker run.
 */
final class DeletedPostRepairWorkerItemResult
{
    public const CLIENT_UNAVAILABLE = 'client_unavailable';

    private string $repairKey;
    private string $claimOutcome;
    private ?DeletedPostRepairExecutionResult $executionResult;

    public function __construct(
        string $repairKey,
        string $claimOutcome,
        ?DeletedPostRepairExecutionResult $executionResult = null
    ) {
        if (! preg_match('/^[a-f0-9]{64}$/D', $repairKey)) {
            throw new InvalidArgumentException('Worker repair key must be a lowercase SHA-256 value.');
        }
        if (('acquired' === $claimOutcome) !== (null !== $executionResult)) {
            throw new InvalidArgumentException('Only an acquired worker claim may contain an execution result.');
        }

        $this->repairKey = $repairKey;
        $this->claimOutcome = $claimOutcome;
        $this->executionResult = $executionResult;
    }

    public function getRepairKey(): string
    {
        return $this->repairKey;
    }

    public function getClaimOutcome(): string
    {
        return $this->claimOutcome;
    }

    public function getExecutionResult(): ?DeletedPostRepairExecutionResult
    {
        return $this->executionResult;
    }
}
