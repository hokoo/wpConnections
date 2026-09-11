<?php

namespace iTRON\wpConnections;

use Psr\Log\LoggerAwareTrait;

class Settings
{
    use LoggerAwareTrait;

    public function init()
    {
        if (defined('WP_DEBUG') && \WP_DEBUG) {
            $this->setLogging();
        }
    }

    protected function setLogging()
    {
        DebugLogObserver::register();
    }
}
