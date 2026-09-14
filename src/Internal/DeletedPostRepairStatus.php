<?php

namespace iTRON\wpConnections\Internal;

use InvalidArgumentException;

final class DeletedPostRepairStatus
{
    public const ARMED = 'armed';
    public const RUNNING = 'running';
    public const RETRY_WAIT = 'retry_wait';
    public const NEEDS_ATTENTION = 'needs_attention';
    public const RESOLVED = 'resolved';

    private const VALUES = [
        self::ARMED,
        self::RUNNING,
        self::RETRY_WAIT,
        self::NEEDS_ATTENTION,
        self::RESOLVED,
    ];

    public static function assertValid(string $status): void
    {
        if (! in_array($status, self::VALUES, true)) {
            throw new InvalidArgumentException('Unknown deleted-post repair status.');
        }
    }
}
