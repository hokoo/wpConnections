<?php

namespace iTRON\wpConnections\Abstracts;

use iTRON\wpConnections\ConnectionCollection;

abstract class Storage {
	/**
	 * @return int Connection ID
	 */
	abstract public function createConnection( \iTRON\wpConnections\Query\Connection $connectionQuery ): int;

	/**
	 * Deletes connections by set of connection IDs.
	 *
	 * @param $connectionIDs    int|int[]   Connection(s) to delete.
	 *
	 * @return                  int         Rows number affected
	 */
	abstract public function deleteSpecificConnections( $connectionIDs ): int;

	/**
	 * Deletes connections by object ID(s).
	 *
	 * @param $objectIDs        int|int[]   Object ID(s) to delete connections with.
	 * @param $onlyFrom         bool        Affect connections with coinciding `from` id
	 * @param $onlyTo           bool        Affect connections with coinciding `to` id
	 *
	 * @return                  int         Rows number affected
	 */
	abstract public function deleteByObjectID( $objectIDs, bool $onlyFrom = false, bool $onlyTo = false ): int;

	/**
	 * Deletes specifically directed connections.
	 * Able to erase multiple connections (e.g. if duplicatable is set true).
	 *
	 * @param int $from     `from` object
	 * @param int $to       `to` object
	 *
	 * @return int          Rows number affected.
	 */
	abstract public function deleteDirectedConnections( int $from = null, int $to = null ): int;

	abstract public function findConnections( \iTRON\wpConnections\Query\Connection $params ): ConnectionCollection;
}
