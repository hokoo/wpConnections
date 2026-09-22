<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Abstracts\Connection as AbstractConnection;
use iTRON\wpConnections\Abstracts\Storage;
use iTRON\wpConnections\Client;
use iTRON\wpConnections\ConnectionCollection;
use iTRON\wpConnections\MetaCollection;
use iTRON\wpConnections\Query\Connection;
use iTRON\wpConnections\Query\MetaCollection as MetaQueryCollection;

class RestPermissionMemoryStorage extends Storage
{
    public function __construct(Client $client)
    {
    }

    public function createConnection(Connection $connection_query): int
    {
        return 1;
    }

    public function updateConnection(AbstractConnection $connection): bool
    {
        return true;
    }

    public function deleteSpecificConnections($connection_ids): int
    {
        return 0;
    }

    public function deleteByObjectID(
        $object_ids,
        string $relation = '',
        bool $only_from = false,
        bool $only_to = false
    ): int {
        return 0;
    }

    public function deleteDirectedConnections(
        ?int $from = null,
        ?int $to = null,
        string $relation = ''
    ): int {
        return 0;
    }

    public function findConnections(Connection $params): ConnectionCollection
    {
        return new ConnectionCollection();
    }

    public function addConnectionMeta(int $object_id, MetaCollection $meta_collection): void
    {
    }

    public function removeConnectionMeta(int $object_id, MetaQueryCollection $meta_query)
    {
        return 0;
    }
}
