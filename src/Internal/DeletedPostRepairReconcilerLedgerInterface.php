<?php

namespace iTRON\wpConnections\Internal;

use DateTimeImmutable;

/**
 * @internal Narrow persistence boundary used by wake-up reconciliation.
 */
interface DeletedPostRepairReconcilerLedgerInterface
{
    /**
     * @param string[] $clientNames
     */
    public function findNextAutomaticWakeupForClients(
        array $clientNames,
        DateTimeImmutable $now
    ): ?DeletedPostRepairAutomaticWakeup;

    public function findOldestResolvedAt(): ?DateTimeImmutable;

    public function recordWakeupFailure(
        string $repairKey,
        DeletedPostRepairDiagnostic $failure,
        DateTimeImmutable $now
    ): bool;
}
