<?php

namespace iTRON\wpConnections\Internal;

use DateTimeImmutable;

/**
 * @internal Narrow arm/claim boundary used by the synchronous coordinator.
 */
interface DeletedPostRepairCoordinatorLedgerInterface
{
    public function armAndTryClaim(
        DeletedPostRepairIdentity $identity,
        object $storage,
        DateTimeImmutable $now,
        DateTimeImmutable $leaseUntil
    ): DeletedPostRepairClaimResult;
}
