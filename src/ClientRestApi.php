<?php

namespace iTRON\wpConnections;

use iTRON\wpConnections\Exceptions\ClientRegisterFail;
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
            foreach ( $this->getClient()->getRelation( $request->get_param('relation') )->findConnections()->toArray() as $connectionItem ) {
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
        $q->set( 'id', $request->get_param( 'connectionID' ) );
        try {
            $connection = $this->getClient()->getRelation( $request->get_param('relation' ) )->findConnections( $q )->first();
        } catch ( Exception $e ) {
            return rest_ensure_response( $this->getError( $e ) );
        } catch ( OutOfBoundsException $e ) {
            return rest_ensure_response( $this->getError( new ConnectionNotFound() ) );
        }

        return rest_ensure_response( $connection );
    }

    /**
     * @param WP_REST_Request $request
     * @return bool
     */
    public function checkPermissions( WP_REST_Request $request ): bool {
        return current_user_can( $this->getClient()->capability );
    }

    public function deleteConnection( WP_REST_Request $request ) {
        $q = new \iTRON\wpConnections\Query\Connection();
        try {
            $rows = $this->getClient()->getRelation( $request->get_param('relation') )->detachConnections( $q->set('id', $request->get_param('connectionID') ) );
        } catch ( Exception $e ) {
            return rest_ensure_response( $this->getError( $e ) );
        }

        if ( 0 === (int) $rows ) {
            return rest_ensure_response( $this->getError( new ConnectionNotFound() ) );
        }

        return rest_ensure_response( self::SUCCESS );
    }

    public function createConnection( WP_REST_Request $request ) {
        $q = new Query\Connection();
        $q->set( 'from', $request->get_param('from') )
            ->set( 'to', $request->get_param('to') )
            ->set( 'order', $request->get_param('order') )
            ->set( 'meta', $request->get_param('meta') );

        try {
            return rest_ensure_response( $this->getClient()->getRelation( $request->get_param('relation') )->createConnection( $q ) );
        } catch ( Exception $e ) {
            return rest_ensure_response( $this->getError( $e ) );
        }
    }

    protected function getRestConnectionItem( Connection $connection ): CollectionItem {
        $response = $this->ensureRestResponseCollectionItem( $connection );
        $response->add_link( 'self', $this->getRestConnectionUrl( $connection->get( 'relation' ), $connection->get( 'id' ) ) );
        return $response;
    }

    protected function ensureRestResponseCollectionItem( $data ): CollectionItem {
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

    /**
     * @throws ClientRegisterFail
     */
    public function registerRestRoutes() {
        $result = [];
        $result []= register_rest_route(
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
            ]
        );

        $result []= register_rest_route(
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

        $result []= register_rest_route(
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

        if ( in_array( false, $result, true ) ) {
            throw new ClientRegisterFail( 'An error has occurred during REST API Routes registering.' );
        }
    }
}
