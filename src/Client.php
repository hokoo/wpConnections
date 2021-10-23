<?php

namespace iTRON\wpConnections;

class Client {
	private $name;

	/**
	 * @var Abstracts\Storage
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
		$this->storageInit();
		$this->relationCollectionInit();

		add_action( 'deleted_post', [ $this->storage, 'deleteByObjectID' ] );

		do_action( 'wpConnections/client/inited', $this );
		do_action( "wpConnections/client/{$this->getName()}/inited", $this );
	}

	protected function storageInit(){
		$this->storage = new Storage( $this );
	}

	protected function relationCollectionInit(){
		$this->relations = new RelationCollection();
	}

	function getName(): string {
		return $this->name;
	}

	function getStorage(): \iTRON\wpConnections\Abstracts\Storage {
		return $this->storage;
	}

	public function getRelations(): RelationCollection {
		return $this->relations;
	}

	/**
	 * Sugar for $this->getRelations()->get()
	 *
	 * @param string $name Connection name.
	 *
	 * @return Relation
	 */
	public function getRelation( string $name ): Relation {
		return $this->relations->get( $name );
	}

	/**
	 * @throws Exceptions\MissingParameters
	 *
	 * @return Relation New relation
	 */
	public function registerRelation( Query\Relation $relationQuery ): Relation {
		$relationQuery->set( 'client', $this );
		$relation = Factory::createRelation( $relationQuery );
		$this->relations->add( $relation );
		return $relation;
	}
}
