<?php

namespace iTRON\wpConnections\Internal;

/**
 * @internal Callback-facing boundary for an automatic bounded worker run.
 */
interface DeletedPostRepairAutomaticRunnerInterface
{
    public function runAutomatically(
        int $limit = 20,
        int $timeBudgetSeconds = 10
    ): DeletedPostRepairWorkerResult;
}
