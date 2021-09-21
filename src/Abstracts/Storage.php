<?php

namespace iTRON\wpConnections\Abstracts;

use iTRON\wpConnections\ConnectionCollection;

abstract class Storage {
	/**
	 * @return int Connection ID
	 */
	abstract public function createConnection( \iTRON\wpConnections\Query\Connection $connectionQuery ): int;

	/**
	 * @param $connectionIDs    int|int[]   Connection(s) to delete.
	 *
	 * @return                  int         Rows number affected
	 */
	abstract public function deleteConnections( $connectionIDs ): int;

	/**
	 * @param $objectIDs    int|int[]   Object ID(s) to delete connections with.
	 *
	 * @return              int         Rows number affected
	 */
	abstract public function deleteByObjectID( $objectIDs ): int;

	abstract public function findConnections( \iTRON\wpConnections\Query\Connection $params ): ConnectionCollection;
}
