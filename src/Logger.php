<?php

namespace iTRON\wpConnections;

use Psr\Log\AbstractLogger;

class Logger extends AbstractLogger
{
    public function __construct(Client $client)
    {
    }

    /**
     * @inheritDoc
     */
    public function log($level, $message, array $context = []): void
    {
        // WP Data Logger plugin compatability.
        do_action('logger', [ $message, $context ], $level);
    }
}
