<?php

namespace iTRON\wpConnections\Abstracts;

use iTRON\wpConnections\MetaCollection;
use iTRON\wpConnections\Query;
use iTRON\wpConnections\ConnectionCollection;
use iTRON\wpConnections\Exceptions\ConnectionWrongData;

abstract class Storage
{
    /**
     * @param Query\Connection $connectionQuery
     * @throws ConnectionWrongData
     *
     * @return int Connection ID
     */
    abstract public function createConnection(Query\Connection $connectionQuery): int;

    /**
     * @return bool Successful or not.
     */
    abstract public function updateConnection(Connection $connection): bool;

    /**
     * Deletes connections by set of connection IDs.
     *
     * @param $connectionIDs    int|int[]   Connection(s) to delete.
     *
     * @return                  int         Rows number affected
     */
    abstract public function deleteSpecificConnections($connectionIDs): int;

    /**
     * Deletes connections by object ID(s).
     *
     * @param $objectIDs        int|int[]   Object ID(s) to delete connections with.
     * @param $relation         string      Relation name. Default all relations
     * @param $onlyFrom         bool        Affect connections with coinciding `from` id
     * @param $onlyTo           bool        Affect connections with coinciding `to` id
     *
     * @return                  int         Rows number affected
     */
    abstract public function deleteByObjectID($objectIDs, string $relation = '', bool $onlyFrom = false, bool $onlyTo = false): int;

    /**
     * Deletes specifically directed connections.
     * Able to erase multiple connections (e.g. if duplicatable is set true).
     *
     * @param int|null $from    `from` object
     * @param int|null $to      `to` object
     * @param string $relation  Relation name. Default all relations
     *
     * @return int              Rows number affected.
     */
    abstract public function deleteDirectedConnections(int $from = null, int $to = null, string $relation = ''): int;

    abstract public function findConnections(Query\Connection $params): ConnectionCollection;

    /**
     * Only adds meta fields to the DB.
     *
     * @param int $objectID
     * @param Query\MetaCollection $metaCollection
     *
     * @return void
     * @throws ConnectionWrongData
     */
    abstract public function addConnectionMeta(int $objectID, MetaCollection $metaCollection): void;

    /**
     * @throws ConnectionWrongData
     */
    abstract public function removeConnectionMeta(int $objectID, Query\MetaCollection $metaQuery);
}
