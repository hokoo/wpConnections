<?php

namespace iTRON\wpConnections;

use iTRON\wpConnections\Exceptions\ConnectionWrongData;
use iTRON\wpConnections\Exceptions\MissingParameters;
use iTRON\wpConnections\Exceptions\RelationWrongData;

class Factory {
	/**
	 * @throws Exceptions\MissingParameters
	 * @throws Exceptions\RelationWrongData
     */
	public static function createRelation( Query\Relation $relationQuery ): Relation {
        $missingParameters = new MissingParameters();

		if ( empty( $relationQuery->get( 'name' ) ) ) {
            $missingParameters->setParam( 'name' );
		}

		if ( empty( $relationQuery->get( 'from' ) ) ) {
            $missingParameters->setParam( 'from' );
		}

		if ( empty( $relationQuery->get( 'name' ) ) ) {
            $missingParameters->setParam( 'name' );
		}

        if ( $missingParameters->getParams() ) {
            throw $missingParameters;
        }

        $relationWrongData = new RelationWrongData( 'Relation has been already created. ' );

        try {
            $exists = $relationQuery->client->getRelation( $relationQuery->name );
        } catch ( Exceptions\RelationNotFound $notFound ) {
            // That's ok, relation name is free.
        }

        if ( isset( $exists ) && $exists instanceof Relation ) {
            $relationWrongData->setParam( $relationQuery->name );
            throw $relationWrongData;
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
	 * @throws ConnectionWrongData
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
