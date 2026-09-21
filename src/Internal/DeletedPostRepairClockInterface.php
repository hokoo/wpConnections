<?php

namespace iTRON\wpConnections\Internal;

use DateTimeImmutable;

interface DeletedPostRepairClockInterface
{
    public function utcNow(): DateTimeImmutable;

    public function monotonicSeconds(): float;
}
