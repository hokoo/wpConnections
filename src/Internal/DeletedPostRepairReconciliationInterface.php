<?php

namespace iTRON\wpConnections\Internal;

/**
 * @internal Testable convergence boundary used by repair delivery adapters.
 */
interface DeletedPostRepairReconciliationInterface
{
    public function reconcile(): DeletedPostRepairReconciliationResult;
}
