<?php

namespace iTRON\wpConnections;

use iTRON\wpConnections\Exceptions\ConnectionNotFound;
use iTRON\wpConnections\Exceptions\Exception;
use iTRON\wpConnections\RestResponse\CollectionItem;
use Ramsey\Collection\Exception\OutOfBoundsException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

class ClientRestApi{
    use ClientInterface;

    const SUCCESS = [ 'result' => 'success' ];

    public $namespace = 'wp-connections/v1';
    public $base = 'client';

    public function __construct( Client $client ) {
        $this->setClient( $client );
    }

    public function init() {
        add_action( 'rest_api_init', [ $this, 'registerRestRoutes' ], 10 );
    }

    public function registerRestRoutes() {
        register_rest_route(
            $this->namespace,
            '/' . $this->base . '/' . $this->getClient()->getName(),
            [
                'args'   => [],
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'description'         => 'Get the client relations.',
                    'callback'            => [ $this, 'getRestClientData' ],
                    'permission_callback' => [ $this, 'checkPermissions' ],
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'description'         => 'Create relation towards the client.',
                    'callback'            => [ $this, 'createRelation' ],
                    'permission_callback' => [ $this, 'checkPermissions' ],
                    'args' => [
                        'relation'  => [
                            'description' => __( 'Unique name for the relation to be created.' ),
                            'type'        => 'string',
                            'required'    => true,
                        ],
                        'from'  => [
                            'description' => __( 'Name CPT\'s that is considered as FROM.' ),
                            'type'        => 'string',
                            'required'    => true,
                        ],
                        'to'  => [
                            'description' => __( 'Name CPT\'s that is considered as TO.' ),
                            'type'        => 'string',
                            'required'    => true,
                        ],
                        'type'  => [
                            'description' => __( 'The type of the relation. Might be `from`, `to`, `both`.' ),
                            'type'        => 'string',
                            'required'    => false,
                        ],
                        'cardinality'  => [
                            'description' => __( 'The cardinality of the relation. Might be `1-1`, `1-m`, `m-m`, `m-1`.' ),
                            'type'        => 'string',
                            'required'    => false,
                        ],
                        'duplicatable'  => [
                            'description' => __( 'Ability of creating the same connections of the relation.' ),
                            'type'        => 'boolean',
                            'required'    => false,
                        ],
                        'closurable'  => [
                            'description' => __( 'Ability to make self-connections.' ),
                            'type'        => 'boolean',
                            'required'    => false,
                        ],
                    ]
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->base . '/' . $this->getClient()->getName() .
            '/relation/' . '(?P<relation>[\w-]+)',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'getRestRelationData' ],
                    'permission_callback' => [ $this, 'checkPermissions' ],
                    'args'   => [
                        'relation' => [
                            'description' => __( 'Unique name for the relation.' ),
                            'type'        => 'string',
                            'required'    => true,
                        ],
                    ],
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'createConnection' ],
                    'permission_callback' => [ $this, 'checkPermissions' ],
                    'args' => [
                        'from'  => [
                            'description' => __( 'Post ID that is considered as FROM.' ),
                            'type'        => 'integer',
                            'required'    => true,
                        ],
                        'to'  => [
                            'description' => __( 'Post ID that is considered as TO.' ),
                            'type'        => 'integer',
                            'required'    => true,
                        ],
                        'order' => [
                            'description' => __( 'Connection order.' ),
                            'type'        => 'integer',
                            'required'    => false,
                            'default'     => 0,
                        ],
                        'meta'  => [
                            'description' => __( 'Connection meta data.' ),
                            'type'        => 'array',
                            'required'    => false,
                            'default'     => [],
                        ]
                    ]
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->base . '/' . $this->getClient()->getName() .
            '/relation/' . '(?P<relation>[\w-]+)' .
            '/(?P<connectionID>[\d]+)',
            [
                'args'   => [
                    'relation' => [
                        'description' => __( 'Unique name for the relation.' ),
                        'type'        => 'string',
                        'required'    => true,
                    ],
                ],
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'getRestConnectionData' ],
                    'permission_callback' => [ $this, 'checkPermissions' ],
                    'args' => [
                        'connectionID' => [
                            'description' => __( 'Connection ID to be retrieved.' ),
                            'type'        => 'integer',
                            'required'    => true,
                        ],
                    ]
                ],
                [
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => [ $this, 'deleteConnection' ],
                    'permission_callback' => [ $this, 'checkPermissions' ],
                    'args' => [
                        'connectionID' => [
                            'description' => __( 'Connection ID to be removed.' ),
                            'type'        => 'integer',
                            'required'    => true,
                        ],
                    ]
                ],
            ]
        );
    }

    public function getRestClientData( WP_REST_Request $request ) {
        $relations = [];
        foreach ( $this->getClient()->getRelations()->toArray() as $relationItem ) {
            /** @var Relation $relationItem */
            $relation = $this->ensureRestResponseCollectionItem( $relationItem );
            $relation->add_link( 'self', $this->getRestRelationUrl( $relationItem->get( 'name' ) ) );
            $relations []= $relation;
        }

        $result = rest_ensure_response( $relations );
        return $result;
    }

    function getRestRelationData( WP_REST_Request $request ) {
        try {
            $response = [];
            foreach ( $this->getClient()->getRelation( $request['relation'] )->findConnections()->toArray() as $connectionItem ) {
                /** @var Connection $connectionItem */
                $response []= $this->getRestConnectionItem( $connectionItem );
            }
        } catch ( Exception $e ) {
            return rest_ensure_response( $this->getError( $e ) );
        }

        return rest_ensure_response( $response );
    }

    public function getRestConnectionData( WP_REST_Request $request ) {
        $q = new Query\Connection();
        $q->set( 'id', $request->get_param( 'id' ) );
        try {
            $connection = $this->getClient()->getRelation( $request['relation'] )->findConnections( $q )->first();
        } catch ( Exception $e ) {
            return rest_ensure_response( $this->getError( $e ) );
        } catch ( OutOfBoundsException $e ) {
            return rest_ensure_response( $this->getError( new ConnectionNotFound() ) );
        }

        return rest_ensure_response( $connection );
    }

    /**
     * @TODO
     *
     * @param WP_REST_Request $request
     * @return bool
     */
    public function checkPermissions( WP_REST_Request $request ): bool{
        return true;
    }

    public function deleteConnection( WP_REST_Request $request ) {
        $q = new \iTRON\wpConnections\Query\Connection();
        try {
            $rows = $this->getClient()->getRelation( $request['relation'] )->detachConnections( $q->set('id', $request['connectionID'] ) );
        } catch ( Exception $e ) {
            return rest_ensure_response( $this->getError( $e ) );
        }

        if ( 0 === (int) $rows ) {
            return rest_ensure_response( $this->getError( new ConnectionNotFound() ) );
        }

        return rest_ensure_response( self::SUCCESS );
    }

    public function createRelation( WP_REST_Request $request ) {

    }

    public function createConnection( WP_REST_Request $request ) {
        $q = new Query\Connection();
        $q->set( 'from', $request['from'] )
            ->set( 'to', $request['to'] )
            ->set( 'order', $request['order'] )
            ->set( 'meta', $request['meta'] );

        try {
            $connection = $this->getClient()->getRelation( $request['relation'] )->createConnection( $q );
        } catch ( Exception $e ) {
            return rest_ensure_response( $this->getError( $e ) );
        }

        return rest_ensure_response( $connection );
    }

    protected function getRestConnectionItem( Connection $connection ): CollectionItem {
        $response = $this->ensureRestResponseCollectionItem( $connection );
        $response->add_link( 'self', $this->getRestConnectionUrl( $connection->get( 'relation' ), $connection->get( 'id' ) ) );
        return $response;
    }

    protected function ensureRestResponseCollectionItem($data ): CollectionItem {
        if ( $data instanceof CollectionItem ) {
            return $data;
        }

        return new CollectionItem( $data );
    }

    protected function getError( \Exception $exception ): WP_Error{
        return new WP_Error( $exception->getCode(), $exception->getMessage() );
    }

    protected function getRestBaseUrl(): string {
        return rest_url( $this->namespace . '/' . $this->base );
    }

    protected function getRestClientUrl(): string {
        return $this->getRestBaseUrl() . '/' . $this->getClient()->getName();
    }

    protected function getRestRelationUrl( string $relationName ): string {
        return $this->getRestClientUrl() . '/relation/' . $relationName;
    }

    protected function getRestConnectionUrl( string $relationName, $connectionID ): string {
        return $this->getRestRelationUrl( $relationName ) . '/' . $connectionID;
    }
}
