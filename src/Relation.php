<?php

namespace iTRON\wpConnections;

use iTRON\wpConnections\Exceptions\ConnectionWrongData;

class Relation extends Abstracts\Relation {
	use ClientInterface;
	use GSInterface;

	public function __construct(){}

	/**
     * @TODO Apply transactions.
     *
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
			$query = new Query\Connection( 0, $connectionQuery->get( 'to' ) );
			$query->set( 'relation', $this->name );

			$check_input = $this->findConnections( $query );
			if ( ! $check_input->isEmpty() ) {
				throw new Exceptions\ConnectionWrongData( 'Cardinality violation.' );
			}
		}

		// Duplicatable check
		$query = new Query\Connection( $connectionQuery->get( 'from' ), $connectionQuery->get( 'to' ) );
		$query->set( 'relation', $this->name );

		$check_duplicatable = $this->findConnections( $query );
		if ( ! $check_duplicatable->isEmpty() ) {
			throw new Exceptions\ConnectionWrongData( 'Duplicatable violation.' );
		}

		// Create connection
		$connectionQuery->set( 'relation', $this->name );

		do_action( 'wpConnections/relation/creating', $connectionQuery );

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
				return $this->getClient()->getStorage()->deleteByObjectID( $connectionQuery->get( 'both' ), $this->name );
			}

			// Detach directed connection(s).
			if ( ! empty( $connectionQuery->get( 'from' ) ) && ! empty( $connectionQuery->get( 'to' ) ) ) {
				return $this->getClient()->getStorage()->deleteDirectedConnections( $connectionQuery->get( 'from' ), $connectionQuery->get( 'to' ), $this->name );
			}

			// Detach `from` directed connections.
			if ( ! empty( $connectionQuery->get( 'from' ) ) ) {
				return $this->getClient()->getStorage()->deleteByObjectID( $connectionQuery->get( 'from' ), $this->name, true );
			}

			// Detach `to` directed connections.
			if ( ! empty( $connectionQuery->get( 'to' ) ) ) {
				return $this->getClient()->getStorage()->deleteByObjectID( $connectionQuery->get( 'to' ), $this->name, false, true );
			}

		} catch ( ConnectionWrongData $e ) {

			// There are no ideas what went wrong.
			return 0;
		}

		// Seems, we have received empty query.
		return 0;
	}

    /**
     * @param Query\Connection|null $connectionQuery
     *
     * @return ConnectionCollection
     */
	public function findConnections( Query\Connection $connectionQuery = null ): ConnectionCollection {
		$connectionQuery = $connectionQuery ?? new Query\Connection();
		$connectionQuery->set( 'relation', $this->name );

		$collection = $this->getClient()->getStorage()->findConnections( $connectionQuery );

        /**
         * Processing the case if specific connection is queried.
         * Since a Storage does not care about addition parameters when ID is specified,
         * we have to ensure that the connection we've just obtained is consistent to the entire query.
         * Particularly, we should check Relation field.
         */
        if ( ! is_null( $connectionQuery->id ) && ! is_null( $connectionQuery->relation ) && ! $collection->isEmpty() ) {
            if ( $connectionQuery->relation !== $collection->first()->relation ) {
                return new ConnectionCollection();
            }
        }

        return $collection;
	}
}
