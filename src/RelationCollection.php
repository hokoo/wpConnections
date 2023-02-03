<?php

namespace iTRON\wpConnections;

use iTRON\wpConnections\Exceptions\RelationNotFound;
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
     * @throws RelationNotFound
     */
	public function get( string $name ): Relation {
        try {
            $result = $this->where( 'name', $name )->first();
        } catch ( OutOfBoundsException $e ) {
            $relation_e = new RelationNotFound( $e );
            $relation_e->setRelation( $name );
            throw $relation_e;
        }

		return $result;
	}
}
