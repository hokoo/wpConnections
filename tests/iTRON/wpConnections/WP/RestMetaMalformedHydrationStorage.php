<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Abstracts\Connection as AbstractConnection;
use iTRON\wpConnections\Abstracts\Storage;
use iTRON\wpConnections\Client;
use iTRON\wpConnections\Connection as DomainConnection;
use iTRON\wpConnections\ConnectionCollection;
use iTRON\wpConnections\MetaCollection;
use iTRON\wpConnections\Query\Connection;
use iTRON\wpConnections\Query\MetaCollection as MetaQueryCollection;

class RestMetaMalformedHydrationStorage extends Storage
{
    public static int $find_calls = 0;
    public static int $mutation_calls = 0;

    public function __construct(Client $client)
    {
    }

    public static function reset(): void
    {
        self::$find_calls = 0;
        self::$mutation_calls = 0;
    }

    public function createConnection(Connection $connection_query): int
    {
        self::$mutation_calls++;

        return 1;
    }

    public function updateConnection(AbstractConnection $connection): bool
    {
        self::$mutation_calls++;

        return true;
    }

    public function deleteSpecificConnections($connection_ids): int
    {
        self::$mutation_calls++;

        return 0;
    }

    public function deleteByObjectID(
        $object_ids,
        string $relation = '',
        bool $only_from = false,
        bool $only_to = false
    ): int {
        self::$mutation_calls++;

        return 0;
    }

    public function deleteDirectedConnections(
        ?int $from = null,
        ?int $to = null,
        string $relation = ''
    ): int {
        self::$mutation_calls++;

        return 0;
    }

    public function findConnections(Connection $params): ConnectionCollection
    {
        self::$find_calls++;

        return new ConnectionCollection([ new DomainConnection(new Connection()) ]);
    }

    public function addConnectionMeta(int $object_id, MetaCollection $meta_collection): void
    {
        self::$mutation_calls++;
    }

    public function removeConnectionMeta(int $object_id, MetaQueryCollection $meta_query)
    {
        self::$mutation_calls++;

        return 0;
    }
}
