<?php

namespace iTRON\wpConnections;

use Ramsey\Collection\Collection;

class MetaCollection extends Collection {
    function __construct( array $data = [] ) {
        $collectionType = Meta::class;
        parent::__construct( $collectionType, $data );
    }

	/**
	 * Returns array of metadata in WordPress way.
	 *
	 * @return array|void
	 */
	public function toArray() : array {
		$origin = parent::toArray();
		if ( $this->isEmpty() ) {
			return $origin;
		}

		$result = [];
		foreach ( $origin as $index => $value ) {
			/** @var Meta $value */
			if ( ! isset( $result[ $value->get_key() ] ) ) {
				$result[ $value->get_key() ] = [];
			}

			$result[ $value->get_key() ] []= $value->get_value();
		}

		return $result;
	}

	/**
	 * Receives array of metadata in WordPress way.
	 *
	 * @return void
	 */
	public function fromArray( array $data ) {
		if ( empty( $data ) ) {
			return;
		}

		foreach ( $data as $key => $value ) {
			$value = is_array( $value ) ? $value : [ $value ];
			foreach ( $value as $term ) {
				$this->add( new Meta( $key, $term ) );
			}
		}
	}
}
