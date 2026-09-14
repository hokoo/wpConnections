<?php

namespace iTRON\wpConnections\Internal;

use InvalidArgumentException;

final class DeletedPostRepairLease
{
    private string $repairKey;
    private string $token;

    public function __construct(string $repairKey, string $token)
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $repairKey)) {
            throw new InvalidArgumentException('Invalid deleted-post repair key.');
        }

        if (! preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new InvalidArgumentException('Invalid deleted-post repair lease token.');
        }

        $this->repairKey = $repairKey;
        $this->token = $token;
    }

    public function getRepairKey(): string
    {
        return $this->repairKey;
    }

    public function getToken(): string
    {
        return $this->token;
    }
}
