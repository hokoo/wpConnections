<?php

namespace iTRON\wpConnections;

use Ramsey\Collection\Collection;
use Ramsey\Collection\Exception\OutOfBoundsException;

class RelationCollection extends Collection {

	function __construct( array $data = [] ) {
		$collectionType = __NAMESPACE__ . '\Relation';
		parent::__construct( $collectionType, $data );
	}

	/**
	 * @param string $name
	 *
	 * @return mixed
	 *
	 * @throws OutOfBoundsException
	 */
	public function get( string $name ): Relation {
		return $this->where( 'name', $name )->first();
	}
}
