<?php

namespace iTRON\wpConnections;

interface AtomicStorageInterface
{
    /**
     * @return mixed Callback result.
     */
    public function runAtomically(callable $operation, TransactionContext $context);
}
