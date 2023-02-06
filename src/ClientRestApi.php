<?php

namespace iTRON\wpConnections;

use iTRON\wpConnections\Exceptions\ConnectionNotFound;
use iTRON\wpConnections\Exceptions\Exception;
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
                    'callback'            => [ $this, 'getRestClientData' ],
                    'permission_callback' => [ $this, 'checkPermissions' ],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->base . '/' . $this->getClient()->getName() .
            '/relation/' . '(?P<relation>[\w-]+)',
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
                    'callback'            => [ $this, 'getRestRelationData' ],
                    'permission_callback' => [ $this, 'checkPermissions' ],
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
                    'connectionID' => [
                        'description' => __( 'Unique name for the connection.' ),
                        'type'        => 'integer',
                        'required'    => true,
                    ],
                ],
                [
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => [ $this, 'deleteConnection' ],
                    'permission_callback' => [ $this, 'checkPermissions' ],
                ],
            ]
        );
    }

    public function getRestClientData( WP_REST_Request $request ) {
        return rest_ensure_response( [ 'relations' => $this->getClient()->getRelations()->toArray() ] );
    }

    function getRestRelationData( WP_REST_Request $request ) {
        try {
            $result = rest_ensure_response( $this->getClient()->getRelation( $request['relation'] )->findConnections()->toArray() );
        } catch ( Exception $e ) {
            return rest_ensure_response( $this->getError( $e ) );
        }

        return $result;
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

    protected function getError( \Exception $exception ): WP_Error{
        return new WP_Error( $exception->getCode(), $exception->getMessage() );
    }
}
