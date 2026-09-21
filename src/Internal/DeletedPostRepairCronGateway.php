<?php

namespace iTRON\wpConnections\Internal;

use Closure;
use Throwable;

/**
 * @internal Dormant callback adapter; I3 owns hook subscription and activation.
 */
final class DeletedPostRepairCronGateway
{
    private DeletedPostRepairAutomaticRunnerInterface $worker;
    private Closure $requestReconciliation;

    public function __construct(
        DeletedPostRepairAutomaticRunnerInterface $worker,
        callable $requestReconciliation
    ) {
        $this->worker = $worker;
        $this->requestReconciliation = Closure::fromCallable($requestReconciliation);
    }

    public function run(): DeletedPostRepairWorkerResult
    {
        try {
            $result = $this->worker->runAutomatically();
        } catch (Throwable $failure) {
            try {
                ($this->requestReconciliation)();
            } catch (Throwable $reconciliationFailure) {
                // Preserve the primary worker/ledger uncertainty.
            }
            throw $failure;
        }

        ($this->requestReconciliation)();

        return $result;
    }
}
