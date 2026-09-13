<?php

namespace iTRON\wpConnections\Exceptions;

class StorageCapabilityUnavailable extends Exception
{
    public const CODE = 312;

    public function __construct(string $message = 'Storage does not support the required atomic operation.')
    {
        parent::__construct($message, self::CODE);
    }
}
