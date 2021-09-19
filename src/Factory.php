<?php

namespace iTRON\wpConnections;

use Exception;

class Factory {
	/**
	 * @throws Exception
	 *
	 * @todo Create custom Exceptions
	 */
	public static function createRelation( $data ): Relation {
		$data = is_object( $data ) ? ( array ) $data : $data;
		if ( ! is_array( $data ) ) {
			throw new Exception( 'Can not create Relation object' );
		}

		if ( empty( $data['name'] ) ) {
			throw new Exception('Missing required fields');
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
}
