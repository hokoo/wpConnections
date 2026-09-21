<?php

namespace iTRON\wpConnections\Exceptions;

use RuntimeException;

final class DeletedPostRepairUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Deleted-post repair service is unavailable.');
    }
}
