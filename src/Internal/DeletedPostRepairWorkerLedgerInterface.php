<?php

namespace iTRON\wpConnections\Internal;

use DateTimeImmutable;

/**
 * @internal Narrow persistence boundary used by the bounded repair worker.
 */
interface DeletedPostRepairWorkerLedgerInterface
{
    /**
     * @param string[] $clientNames
     * @return DeletedPostRepairRecord[]
     */
    public function findDueForClients(
        array $clientNames,
        int $limit,
        DateTimeImmutable $now
    ): array;

    public function tryClaimDue(
        string $repairKey,
        object $storage,
        DateTimeImmutable $now,
        DateTimeImmutable $leaseUntil
    ): DeletedPostRepairClaimResult;

    public function tryClaimDueManually(
        string $repairKey,
        object $storage,
        DateTimeImmutable $now,
        DateTimeImmutable $leaseUntil
    ): DeletedPostRepairClaimResult;

    public function findOldestResolvedAt(): ?DateTimeImmutable;

    public function purgeResolvedBefore(DateTimeImmutable $cutoff, int $limit): int;
}
