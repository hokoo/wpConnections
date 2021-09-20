<?php

namespace iTRON\wpConnections;

use Exception;

class Factory {
	/**
	 * @throws Exceptions\MissingParameters
	 */
	public static function createRelation( Query\Relation $relationQuery ): Relation {
		if ( empty( $relationQuery->get( 'name' ) ) ) {
			$e = new Exceptions\MissingParameters('Missing required fields');
			$e->setParam( 'name' );

			throw $e;
		}

		$default = [
			'type'          => 'both',
			'cardinality'   => 'm-m',
			'duplicatable'  => false,
			'closurable'    => false,
		];

		$args = wp_parse_args( $default, $relationQuery );

		$relation = new Relation();
		foreach ( $args as $field => $value ) {
			$relation->set( $field, $value );
		}

		return $relation;
	}

	public static function createConnect( array $data ): Connection {


		return new Connection();
	}
}
