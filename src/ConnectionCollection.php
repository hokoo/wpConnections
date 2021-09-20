<?php

namespace iTRON\wpConnections;

use Ramsey\Collection\Collection;

class ConnectionCollection extends Collection {
	function __construct( array $data = [] ) {
		$collectionType = __NAMESPACE__ . '\Connection';
		parent::__construct( $collectionType, $this->fromArray( $data ) );
	}

	private function fromArray( array $items ): array {

		$connections = [];
		$type = $this->getType();

		foreach ( $items as $item ) {
			if ( $item instanceof $type ) {
				$connections []= $item;
				continue;
			}

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
				->set( 'title', $item->title ?? '' );

			$connections []= $connection;
		}

		return $connections;
	}

}
