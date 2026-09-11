<?php

namespace iTRON\wpConnections\Exceptions;

class ConnectionRelationMismatch extends ConnectionWrongData
{
    public function __construct(string $expectedRelation, string $actualRelation)
    {
        parent::__construct(
            "Connection relation identity mismatch: expected {$expectedRelation}, got {$actualRelation}.",
            310
        );
    }
}
