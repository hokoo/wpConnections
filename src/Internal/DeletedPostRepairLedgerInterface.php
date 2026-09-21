<?php

namespace iTRON\wpConnections\Internal;

use DateTimeImmutable;

/**
 * @internal Narrow persistence boundary used by the repair executor.
 */
interface DeletedPostRepairLedgerInterface
{
    public function findForClient(string $clientName, string $repairKey): ?DeletedPostRepairRecord;

    public function markRetryWait(
        DeletedPostRepairLease $lease,
        DeletedPostRepairDiagnostic $failure,
        DateTimeImmutable $nextAttemptAt,
        DateTimeImmutable $now
    ): bool;

    public function markNeedsAttention(
        DeletedPostRepairLease $lease,
        DeletedPostRepairDiagnostic $failure,
        DateTimeImmutable $now
    ): bool;

    public function markResolved(DeletedPostRepairLease $lease, DateTimeImmutable $now): bool;

    public function deleteTransientSuccess(DeletedPostRepairLease $lease): bool;
}
