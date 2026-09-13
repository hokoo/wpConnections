<?php

namespace iTRON\wpConnections\Exceptions;

use Throwable;

class StorageFailure extends Exception
{
    public const CODE = 311;

    private string $operation;

    public function __construct(string $operation, Throwable $previous = null)
    {
        $this->operation = $operation;

        parent::__construct("Storage operation failed: {$operation}.", self::CODE, $previous);
    }

    public function getOperation(): string
    {
        return $this->operation;
    }
}
