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
        $f = function (...$data) {
            $this->logger->debug(current_action(), [...$data]);
        };

        add_action('wpConnections/storage/findConnections/dbQuery', $f, 10, 2);
        add_action('wpConnections/storage/removeConnectionMeta/after', $f, 10, 5);
        add_action('wpConnections/storage/deletedSpecificConnections', $f, 10, 3);
    }
}
