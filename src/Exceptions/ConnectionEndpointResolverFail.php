<?php

namespace iTRON\wpConnections\Exceptions;

use Throwable;

class ConnectionEndpointResolverFail extends ConnectionWrongData
{
    public function __construct(string $side, string $entityType, int $entityId, ?Throwable $previous = null)
    {
        parent::__construct(
            "Connection endpoint resolver failed: {$side}={$entityType}#{$entityId}.",
            309,
            $previous
        );
    }
}
