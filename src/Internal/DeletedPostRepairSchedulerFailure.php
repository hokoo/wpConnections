<?php

namespace iTRON\wpConnections\Internal;

use RuntimeException;

/**
 * @internal Safe scheduler boundary failure without WordPress option details.
 */
final class DeletedPostRepairSchedulerFailure extends RuntimeException
{
    public function __construct(string $operation)
    {
        parent::__construct('Deleted-post repair scheduler failed to ' . $operation . '.');
    }
}
