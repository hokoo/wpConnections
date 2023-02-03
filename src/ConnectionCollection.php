<?php

namespace iTRON\wpConnections;

use Ramsey\Collection\Collection;

class ConnectionCollection extends Collection {
	function __construct( array $data = [] ) {
		$collectionType = __NAMESPACE__ . '\Connection';
		parent::__construct( $collectionType, $this->fromArray( $data, $collectionType ) );
	}

    /**
     * @TODO
     *
     * Returns WP_Post[] based on 'from' or 'to' direction type.
     */
    public function getPosts( string $direction ) {}

	private function fromArray( array $items, string $collectionType = '' ): array {

		$connections = [];
		foreach ( $items as $item ) {
			if ( $item instanceof $collectionType ) {
				$connections []= $item;
				continue;
			}

			$item = ( object ) $item;

			if ( ! isset( $item->ID ) ) continue;
			if ( ! isset( $item->from ) ) continue;
			if ( ! isset( $item->to ) ) continue;
			if ( ! isset( $item->order ) ) continue;

			$connection = new Connection();
			$connection
				->set( 'id', $item->ID )
				->set( 'from', $item->from )
				->set( 'to', $item->to )
				->set( 'order', $item->order )
				->set( 'title', $item->title ?? '' )
				->set( 'client', $item->client ?? null )
				->set( 'meta', $item->meta ?? [] );

			$connections []= $connection;
		}

		return $connections;
	}
}
