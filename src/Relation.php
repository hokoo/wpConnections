<?php

namespace iTRON\wpConnections;

class Relation extends Abstracts\Relation {
	use ClientInterface;
	use GSInterface;

	public function __construct(){}

	/**
	 * Creates new connect
	 */
	public function createConnection(){}

	/**
	 * Detaches connection
	 */
	public function detachConnection(){}

	/**
	 * @param Query\Connection $params
	 *
	 * @return ConnectionCollection
	 */
	public function findConnections( Query\Connection $params ): ConnectionCollection {
		return $this->getClient()->getStorage()->findConnections( $params );
	}
}
