<?php

namespace iTRON\wpConnections;

use iTRON\wpConnections\Exceptions\ConnectionWrongData;

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
			$query = new Query\Connection( $connectionQuery->get( 'from' ) );
			$query->set( 'relation', $this->name );

			$check_output = $this->findConnections( $query );
			if ( ! $check_output->isEmpty() ) {
				throw new Exceptions\ConnectionWrongData( 'Cardinality violation.' );
			}
		}

		if ( '1' === $input ) {
			$query = new Query\Connection( null, $connectionQuery->get( 'to' ) );
			$query->set( 'relation', $this->name );

			$check_input = $this->findConnections( $query );
			if ( ! $check_input->isEmpty() ) {
				throw new Exceptions\ConnectionWrongData( 'Cardinality violation.' );
			}
		}

		// Create connection
		$connectionQuery->set( 'relation', $this->name );
		return Factory::createConnection( $connectionQuery, $this );
	}

	/**
	 * Detaches connection.
	 * Able to detach multiple connections if $connectionQuery->id are not set.
	 *
	 * @return int Connections number detached.
	 */
	public function detachConnections( Query\Connection $connectionQuery ): int {

		try {
			// Detach specific connection.
			if ( ! empty( $connectionQuery->get( 'id' ) ) ) {
				return $this->getClient()->getStorage()->deleteSpecificConnections( $connectionQuery->get( 'id' ) );
			}

			// Detach any connection with $connectionQuery->both as object ID.
			if ( ! empty( $connectionQuery->get( 'both' ) ) ) {
				return $this->getClient()->getStorage()->deleteByObjectID( $connectionQuery->get( 'both' ) );
			}

			// Detach directed connection(s).
			if ( ! empty( $connectionQuery->get( 'from' ) ) && ! empty( $connectionQuery->get( 'to' ) ) ) {
				return $this->getClient()->getStorage()->deleteDirectedConnections( $connectionQuery->get( 'from' ), $connectionQuery->get( 'to' ) );
			}

			// Detach directed connection(s).
			if ( ! empty( $connectionQuery->get( 'from' ) ) ) {
				return $this->getClient()->getStorage()->deleteByObjectID( $connectionQuery->get( 'from' ), true );
			}

			// Detach directed connection(s).
			if ( ! empty( $connectionQuery->get( 'to' ) ) ) {
				return $this->getClient()->getStorage()->deleteByObjectID( $connectionQuery->get( 'to' ), false, true );
			}

		} catch ( ConnectionWrongData $e ) {

			// There are no ideas what went wrong.
			return 0;
		}

		// Seems, we have received empty query.
		return 0;
	}

	/**
	 * @param Query\Connection $connectionQuery
	 *
	 * @return ConnectionCollection
	 */
	public function findConnections( Query\Connection $connectionQuery ): ConnectionCollection {
		return $this->getClient()->getStorage()->findConnections( $connectionQuery );
	}
}
