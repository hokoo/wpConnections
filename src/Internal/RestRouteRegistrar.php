<?php

namespace iTRON\wpConnections\Internal;

use WP_REST_Server;

/**
 * Registers the built-in transport with context-neutral callbacks.
 *
 * @internal
 */
final class RestRouteRegistrar
{
    public static function register(
        WP_REST_Server $server,
        RestRouteIdentity $identity,
        RestRouteBoundary $boundary
    ): void {
        $namespace = $identity->getNamespace();
        $clientRoute = '/' . $namespace . $identity->getClientRoute();
        $relationRoute = $clientRoute . '/relation/' . '(?P<relation>[\w-]+)';
        $connectionRoute = $relationRoute . '/(?P<connectionID>[\d]+)';
        $metaRoute = $connectionRoute . '/meta';

        $server->register_route(
            $namespace,
            $clientRoute,
            [
                'args' => [],
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'description'         => 'Get the client relations.',
                    'callback'            => [ $boundary, 'getTheClient' ],
                    'permission_callback' => [ $boundary, 'checkPermissions' ],
                ],
            ],
            true
        );

        $server->register_route(
            $namespace,
            $relationRoute,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $boundary, 'getRelation' ],
                    'permission_callback' => [ $boundary, 'checkPermissions' ],
                    'args'                => [
                        'relation' => [
                            'description' => __('Unique name for the relation.'),
                            'type'        => 'string',
                            'required'    => true,
                        ],
                    ],
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $boundary, 'createConnection' ],
                    'permission_callback' => [ $boundary, 'checkPermissions' ],
                    'args'                => [
                        'from' => [
                            'description' => __('Post ID that is considered as FROM.'),
                            'type'        => 'integer',
                            'required'    => true,
                        ],
                        'to' => [
                            'description' => __('Post ID that is considered as TO.'),
                            'type'        => 'integer',
                            'required'    => true,
                        ],
                        'order' => [
                            'description' => __('Connection order.'),
                            'type'        => 'integer',
                            'required'    => false,
                            'default'     => 0,
                        ],
                        'meta' => [
                            'description' => __('Connection meta data.'),
                            'type'        => 'array',
                            'required'    => false,
                            'default'     => [],
                        ],
                    ],
                ],
            ],
            true
        );

        $server->register_route(
            $namespace,
            $connectionRoute,
            [
                'args' => [
                    'relation' => [
                        'description' => __('Unique name for the relation.'),
                        'type'        => 'string',
                        'required'    => true,
                    ],
                ],
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $boundary, 'getConnection' ],
                    'permission_callback' => [ $boundary, 'checkPermissions' ],
                    'args'                => [
                        'connectionID' => [
                            'description' => __('Connection ID to be retrieved.'),
                            'type'        => 'integer',
                            'required'    => true,
                        ],
                    ],
                ],
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [ $boundary, 'updateConnection' ],
                    'permission_callback' => [ $boundary, 'checkPermissions' ],
                    'args'                => [
                        'connectionID' => [
                            'type'     => 'integer',
                            'required' => true,
                        ],
                        'from' => [
                            'description' => __('Post ID that is considered as FROM.'),
                            'type'        => 'integer',
                            'required'    => true,
                        ],
                        'to' => [
                            'description' => __('Post ID that is considered as TO.'),
                            'type'        => 'integer',
                            'required'    => true,
                        ],
                        'order' => [
                            'description' => __('Connection order.'),
                            'type'        => 'integer',
                            'required'    => false,
                            'default'     => 0,
                        ],
                    ],
                ],
                [
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => [ $boundary, 'deleteConnection' ],
                    'permission_callback' => [ $boundary, 'checkPermissions' ],
                    'args'                => [
                        'connectionID' => [
                            'description' => __('Connection ID to be removed.'),
                            'type'        => 'integer',
                            'required'    => true,
                        ],
                    ],
                ],
            ],
            true
        );

        $server->register_route(
            $namespace,
            $metaRoute,
            [
                'args' => [
                    'relation' => [
                        'description' => __('Unique name for the relation.'),
                        'type'        => 'string',
                        'required'    => true,
                    ],
                    'connectionID' => [
                        'description' => __('Unique ID of the connection.'),
                        'type'        => 'integer',
                        'required'    => true,
                    ],
                ],
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [ $boundary, 'updateConnectionMeta' ],
                    'permission_callback' => [ $boundary, 'checkPermissions' ],
                    'args'                => [
                        'meta' => [
                            'description' => __('Add connection meta data.'),
                            'type'        => 'array',
                            'required'    => false,
                            'default'     => [],
                        ],
                    ],
                ],
                [
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => [ $boundary, 'deleteConnectionMeta' ],
                    'permission_callback' => [ $boundary, 'checkPermissions' ],
                    'args'                => [
                        'connectionID' => [
                            'description' => __('Connection ID of the meta to be removed.'),
                            'type'        => 'integer',
                            'required'    => true,
                        ],
                    ],
                ],
            ],
            true
        );
    }
}
