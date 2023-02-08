<?php

namespace iTRON\wpConnections;

use iTRON\wpConnections\Exceptions\RelationNotFound;
use iTRON\wpConnections\Exceptions\RelationWrongData;
use iTRON\wpConnections\Exceptions\MissingParameters;

class Client {
	private string $name;
	private Abstracts\Storage $storage;
	private RelationCollection $relations;

    /**
     * WP user capability id that is required for performing actions with client.
     */
    public string $capability = 'manage_options';

	public function __construct( $name ) {
		$this->name = sanitize_title( $name );
		$this->init();
	}

    public function getName(): string {
		return $this->name;
	}

    public function getStorage(): Abstracts\Storage {
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
     * @throws RelationNotFound
     */
	public function getRelation( string $name ): Relation {
		return $this->relations->get( $name );
	}

	/**
	 * @return Relation Registers new relation.
	 *
     * @throws RelationWrongData
     * @throws MissingParameters
     */
	public function registerRelation( Query\Relation $relationQuery ): Relation {
		$relationQuery->set( 'client', $this );
		$relation = Factory::createRelation( $relationQuery );
		$this->relations->add( $relation );
		return $relation;
	}

    private function init() {
        $this->storage = new Storage( $this );
        $this->relations = new RelationCollection();
        $this->registerRestApi();

        add_action( 'deleted_post', [ $this->storage, 'deleteByObjectID' ] );

        do_action( 'wpConnections/client/inited', $this );
        do_action( "wpConnections/client/{$this->getName()}/inited", $this );
    }

    private function registerRestApi() {
        $class = ClientRestApi::class;
        $class = apply_filters( 'wpConnections/client/restApi', $class, $this );
        $class = apply_filters( 'wpConnections/client/restApi/' . $this->getName(), $class, $this );

        // Class should be ClientRestApi's descendant or itself.
        /** @var ClientRestApi $restapi */
        $restapi = new $class( $this );
        $restapi->init();
    }
}
