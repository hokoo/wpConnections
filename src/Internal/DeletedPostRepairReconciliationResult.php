<?php

namespace iTRON\wpConnections\Internal;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * @internal Safe observation of one best-effort wake-up reconciliation.
 */
final class DeletedPostRepairReconciliationResult
{
    public const NO_WORK = 'no_work';
    public const KEPT = 'kept';
    public const SCHEDULED = 'scheduled';
    public const SCHEDULED_WITH_DUPLICATE = 'scheduled_with_duplicate';
    public const UNSCHEDULED = 'unscheduled';
    public const FAILED = 'failed';

    private const OUTCOMES = [
        self::NO_WORK,
        self::KEPT,
        self::SCHEDULED,
        self::SCHEDULED_WITH_DUPLICATE,
        self::UNSCHEDULED,
        self::FAILED,
    ];

    private string $outcome;
    private ?DateTimeImmutable $desiredAt;
    private ?DateTimeImmutable $scheduledAt;
    private bool $automaticDispatchAvailable;
    private ?DeletedPostRepairDiagnostic $diagnostic;

    public function __construct(
        string $outcome,
        ?DateTimeImmutable $desiredAt,
        ?DateTimeImmutable $scheduledAt,
        bool $automaticDispatchAvailable,
        ?DeletedPostRepairDiagnostic $diagnostic = null
    ) {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException('Unknown repair reconciliation outcome.');
        }

        $this->outcome = $outcome;
        $this->desiredAt = $desiredAt;
        $this->scheduledAt = $scheduledAt;
        $this->automaticDispatchAvailable = $automaticDispatchAvailable;
        $this->diagnostic = $diagnostic;
    }

    public function getOutcome(): string
    {
        return $this->outcome;
    }

    public function getDesiredAt(): ?DateTimeImmutable
    {
        return $this->desiredAt;
    }

    public function getScheduledAt(): ?DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function isAutomaticDispatchAvailable(): bool
    {
        return $this->automaticDispatchAvailable;
    }

    public function getDiagnostic(): ?DeletedPostRepairDiagnostic
    {
        return $this->diagnostic;
    }
}
