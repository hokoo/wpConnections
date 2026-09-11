<?php

namespace iTRON\wpConnections;

/**
 * Routes public storage diagnostics to the logger owned by their origin Client.
 *
 * @internal
 */
final class DebugLogObserver
{
    private const PRIORITY = 10;

    private static ?self $instance = null;

    public static function register(): void
    {
        $observer = self::$instance ??= new self();

        add_action(
            'wpConnections/storage/findConnections/dbQuery',
            [ $observer, 'logQuery' ],
            self::PRIORITY,
            3
        );
        add_action(
            'wpConnections/storage/removeConnectionMeta/after',
            [ $observer, 'logMutation' ],
            self::PRIORITY,
            5
        );
        add_action(
            'wpConnections/storage/deletedSpecificConnections',
            [ $observer, 'logMutation' ],
            self::PRIORITY,
            3
        );
    }

    /**
     * The trailing Client is routing metadata and is not added to legacy logs.
     *
     * @param mixed $query
     * @param mixed $result
     * @param mixed $origin
     */
    public function logQuery($query, $result, $origin = null): void
    {
        if (! $origin instanceof Client) {
            return;
        }

        $this->log($origin, [ $query, $result ]);
    }

    /**
     * @param mixed ...$data
     */
    public function logMutation(...$data): void
    {
        $origin = $data[0] ?? null;
        if (! $origin instanceof Client) {
            return;
        }

        $this->log($origin, $data);
    }

    private function log(Client $origin, array $context): void
    {
        $origin->getLogger()->debug(current_action(), $context);
    }
}
