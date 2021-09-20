<?php

namespace iTRON\wpConnections;

class Client {
	private $name;

	/**
	 * @var Storage
	 */
	private $storage;

	/**
	 * @var RelationCollection
	 */
	private $relations;

	public function __construct( $name ) {
		$this->name = sanitize_title( $name );
		$this->init();
	}

	private function init() {
		$this->storage = new Storage( $this );
		$this->relations = new RelationCollection();

		do_action( 'p2p_client_inited', $this );
	}

	function getName(): string {
		return $this->name;
	}

	function getStorage(): Storage {
		return $this->storage;
	}

	public function getRelations(): RelationCollection {
		return $this->relations;
	}

	/**
	 * Sugar for $this->getRelations()->get()
	 *
	 * @param string $name Relation name.
	 *
	 * @return Relation
	 */
	public function getRelation( string $name ): Relation {
		return $this->relations->get( $name );
	}

	/**
	 * @throws Exceptions\RelationWrongData
	 * @throws Exceptions\MissingParameters
	 */
	public function createRelation( array $data ): self {
		$this->relations->add( Factory::createRelation( $data ) );
		return $this;
	}
}
