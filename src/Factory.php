<?php

namespace iTRON\wpConnections;

class Factory {
	/**
	 * @throws Exceptions\MissingParameters
	 */
	public static function createRelation( Query\Relation $relationQuery ): Relation {
		if ( empty( $relationQuery->get( 'name' ) ) ) {
			$e = new Exceptions\MissingParameters();
			$e->setParam( 'name' );

			throw $e;
		}

		$default = [
			'type'          => 'both',
			'cardinality'   => 'm-m',
			'duplicatable'  => false,
			'closurable'    => false,
		];

		$args = wp_parse_args( $relationQuery, $default );

		$relation = new Relation();
		foreach ( $args as $field => $value ) {
			$relation->set( $field, $value );
		}

		return $relation;
	}

	/**
	 * @throws Exceptions\ConnectionWrongData
	 */
	public static function createConnection( Query\Connection $connectionQuery, Relation $relation ): Connection {
		$connectionID = $relation->getClient()->getStorage()->createConnection( $connectionQuery );
		$connection = new Connection();
		$connection
			->loadFromQuery( $connectionQuery )
			->set( 'id', $connectionID );

		return $connection;
	}
}
