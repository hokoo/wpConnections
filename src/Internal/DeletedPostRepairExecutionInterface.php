<?php

namespace iTRON\wpConnections\Internal;

use iTRON\wpConnections\Client;

/**
 * @internal Testable execution boundary used by the bounded repair worker.
 */
interface DeletedPostRepairExecutionInterface
{
    public function execute(
        Client $client,
        DeletedPostRepairLease $lease,
        string $mode
    ): DeletedPostRepairExecutionResult;
}
