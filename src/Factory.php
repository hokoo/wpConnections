<?php

namespace iTRON\wpConnections;

use Exception;

class Factory {
	/**
	 * @throws Exceptions\RelationWrongData
	 * @throws Exceptions\MissingParameters
	 */
	public static function createRelation( $data ): Relation {
		$data = is_object( $data ) ? ( array ) $data : $data;
		if ( ! is_array( $data ) ) {
			throw new Exceptions\RelationWrongData( 'Can not create Connection object based on the data passed in.' );
		}

		if ( empty( $data['name'] ) ) {
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

		$args = wp_parse_args( $default, $data );

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
