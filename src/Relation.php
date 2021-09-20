<?php

namespace iTRON\wpConnections;

class Relation extends Abstracts\Relation {
	use ClientInterface;
	use GSInterface;

	public function __construct(){}

	/**
	 * Creates new connect
	 *
	 * @param Query\Connection $connectionQuery
	 *
	 * @return Connection
	 * @throws Exceptions\MissingParameters
	 * @throws Exceptions\ConnectionWrongData
	 */
	public function createConnection( Query\Connection $connectionQuery ): Connection {

		// Required fields
		if (
			empty( $connectionQuery->get( 'from' ) ) ||
			empty( $connectionQuery->get( 'to' ) )
		) {
			$e = new Exceptions\MissingParameters();
			$e
				->setParam( 'from' )
				->setParam( 'to' );

			throw $e;
		}

		// Self-connection ability
		if ( ! $this->closurable && $connectionQuery->get( 'from' ) === $connectionQuery->get( 'to' ) ) {
			throw new Exceptions\ConnectionWrongData( 'Closurable not allowed by relation settings.' );
		}

		// Cardinality check
		$cardinality = explode( '-', $this->cardinality );
		$output = $cardinality[0];
		$input  = $cardinality[1];

		if ( '1' === $output ) {
			$check_output = $this->findConnections( new Query\Connection( $connectionQuery->get( 'from' ) ) );
			if ( ! $check_output->isEmpty() ) {
				throw new Exceptions\ConnectionWrongData( 'Cardinality violation.' );
			}
		}

		if ( '1' === $input ) {
			$check_input = $this->findConnections( new Query\Connection( null, $connectionQuery->get( 'to' ) ) );
			if ( ! $check_input->isEmpty() ) {
				throw new Exceptions\ConnectionWrongData( 'Cardinality violation.' );
			}
		}

		// Create connection
		$connectionQuery->set( 'relation', $this->name );
		return Factory::createConnection( $connectionQuery, $this );
	}

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
