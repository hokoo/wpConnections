<?php

namespace iTRON\wpConnections\Internal;

use iTRON\wpConnections\Exceptions\ConnectionWrongData;
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
            self::normalizeRouteArguments([
                'args' => [],
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'description'         => 'Get the client relations.',
                    'callback'            => [ $boundary, 'getTheClient' ],
                    'permission_callback' => [ $boundary, 'checkPermissions' ],
                ],
            ]),
            true
        );

        $server->register_route(
            $namespace,
            $relationRoute,
            self::normalizeRouteArguments([
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
                        'from' => self::positiveSelectorArgument(
                            __('Positive FROM endpoint ID.')
                        ),
                        'to' => self::positiveSelectorArgument(
                            __('Positive TO endpoint ID.')
                        ),
                        'both' => self::positiveSelectorArgument(
                            __('Positive endpoint ID matching either side.')
                        ),
                        'target' => [
                            'description' => __('Endpoint roles to project.'),
                            'type' => 'string',
                            'enum' => [ 'from', 'to', 'both', 'opposite' ],
                            'validate_callback' => static function ($value, \WP_REST_Request $request): bool {
                                if (! self::queryOnlyArgument($request, 'target')) {
                                    return false;
                                }
                                $query = $request->get_query_params();
                                $candidate = $query['target'] ?? $value;
                                if (! is_string($candidate) || ! in_array($candidate, [ 'from', 'to', 'both', 'opposite' ], true)) {
                                    return false;
                                }
                                if ('opposite' !== $candidate) {
                                    return true;
                                }
                                return 1 === count(array_intersect([ 'from', 'to', 'both' ], array_keys($query)));
                            },
                        ],
                        'representation' => [
                            'description' => __('Opt-in relation representation.'),
                            'type' => 'string',
                            'enum' => [ 'expanded' ],
                            'validate_callback' => static function ($value, \WP_REST_Request $request): bool {
                                if (! self::queryOnlyArgument($request, 'representation')) {
                                    return false;
                                }
                                $query = $request->get_query_params();
                                return 'expanded' === ($query['representation'] ?? $value);
                            },
                        ],
                        'entity' => [
                            'description' => __('Filters for projected entities.'),
                            'type' => 'object',
                            'validate_callback' => [ self::class, 'validateEntityFilters' ],
                        ],
                        'context' => [
                            'description' => __('REST field context for projected entities.'),
                            'type' => 'string',
                            'enum' => [ 'view', 'embed', 'edit' ],
                            'validate_callback' => static function ($value, \WP_REST_Request $request): bool {
                                if (! self::queryOnlyArgument($request, 'context')) {
                                    return false;
                                }
                                $query = $request->get_query_params();
                                return in_array($query['context'] ?? $value, [ 'view', 'embed', 'edit' ], true);
                            },
                        ],
                        'page' => self::positivePaginationArgument(__('Page number.')),
                        'per_page' => self::positivePaginationArgument(__('Items per page, up to 100.'), 100),
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
            ]),
            true
        );

        $server->register_route(
            $namespace,
            $connectionRoute,
            self::normalizeRouteArguments([
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
                self::connectionUpdateHandler($boundary, 'POST', true),
                self::connectionUpdateHandler($boundary, 'PUT', true),
                self::connectionUpdateHandler($boundary, 'PATCH', false),
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
            ]),
            true
        );

        $server->register_route(
            $namespace,
            $metaRoute,
            self::normalizeRouteArguments([
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
                        'meta' => [
                            'description' => __('Metadata selectors as key/value rows or an associative key/value map; a null row value selects every value for its key, while omitted, empty, or top-level null input deletes all metadata.'),
                            'required'    => false,
                            'default'     => [],
                        ],
                    ],
                ],
            ]),
            true
        );
    }

    /**
     * Preserves register_rest_route() common-argument inheritance while
     * binding routes to an explicit REST server instance.
     */
    private static function normalizeRouteArguments(array $routeArguments): array
    {
        $commonArguments = $routeArguments['args'] ?? [];
        unset($routeArguments['args']);

        if (isset($routeArguments['callback'])) {
            $routeArguments = [ $routeArguments ];
        }

        $defaults = [
            'methods'  => 'GET',
            'callback' => null,
            'args'     => [],
        ];

        foreach ($routeArguments as $key => $argumentGroup) {
            if (! is_numeric($key)) {
                continue;
            }

            $argumentGroup = array_merge($defaults, $argumentGroup);
            $argumentGroup['args'] = array_merge(
                $commonArguments,
                $argumentGroup['args']
            );
            $routeArguments[ $key ] = $argumentGroup;
        }

        return $routeArguments;
    }

    private static function positiveSelectorArgument(string $description): array
    {
        return [
            'description'       => $description,
            'type'              => 'integer',
            'minimum'           => 1,
            'required'          => false,
            'validate_callback' => static function (
                $value,
                \WP_REST_Request $request,
                string $parameter
            ): bool {
                $queryParameters = $request->get_query_params();
                $candidate = array_key_exists($parameter, $queryParameters)
                    ? $queryParameters[ $parameter ]
                    : $value;

                try {
                    ConnectionIdNormalizer::one($candidate);
                } catch (ConnectionWrongData $exception) {
                    return false;
                }

                return true;
            },
        ];
    }

    /** @internal WordPress route argument callback. */
    public static function validateEntityFilters($value, \WP_REST_Request $request): bool
    {
        if (! self::queryOnlyArgument($request, 'entity')) {
            return false;
        }
        $query = $request->get_query_params();
        $candidate = $query['entity'] ?? $value;
        if (! is_array($candidate) || [] === $candidate || ! isset($query['target'])) {
            return false;
        }
        foreach ($candidate as $field => $values) {
            if (! in_array($field, [ 'status', 'type', 'slug', 'search' ], true)) {
                return false;
            }
            $values = is_array($values) ? $values : [ $values ];
            if ([] === $values || array_keys($values) !== range(0, count($values) - 1)) {
                return false;
            }
            foreach ($values as $filterValue) {
                if (! is_string($filterValue) || '' === trim($filterValue)) {
                    return false;
                }
            }
        }
        return true;
    }

    private static function positivePaginationArgument(string $description, ?int $maximum = null): array
    {
        return [
            'description' => $description,
            'type' => 'integer',
            'minimum' => 1,
            'maximum' => $maximum,
            'validate_callback' => static function ($value, \WP_REST_Request $request, string $parameter) use ($maximum): bool {
                if (! self::queryOnlyArgument($request, $parameter)) {
                    return false;
                }
                $query = $request->get_query_params();
                $candidate = $query[$parameter] ?? $value;
                try {
                    $number = ConnectionIdNormalizer::one($candidate);
                    return null === $maximum || $number <= $maximum;
                } catch (ConnectionWrongData $exception) {
                    return false;
                }
            },
        ];
    }

    private static function queryOnlyArgument(\WP_REST_Request $request, string $parameter): bool
    {
        $json = $request->get_json_params();
        return array_key_exists($parameter, $request->get_query_params())
            && ! array_key_exists($parameter, $request->get_body_params())
            && (! is_array($json) || ! array_key_exists($parameter, $json));
    }

    private static function connectionUpdateHandler(
        RestRouteBoundary $boundary,
        string $method,
        bool $requireEndpoints
    ): array {
        $arguments = [
            'connectionID' => [
                'type'     => 'integer',
                'minimum'  => 1,
                'required' => true,
            ],
            'from' => [
                'description' => __('Post ID that is considered as FROM.'),
                'type'        => 'integer',
                'minimum'     => 1,
                'required'    => $requireEndpoints,
            ],
            'to' => [
                'description' => __('Post ID that is considered as TO.'),
                'type'        => 'integer',
                'minimum'     => 1,
                'required'    => $requireEndpoints,
            ],
            'title' => [
                'description' => __('Connection title.'),
                'type'        => [ 'string', 'null' ],
                'required'    => false,
            ],
            'order' => [
                'description' => __('Connection order.'),
                'type'        => 'integer',
                'minimum'     => 0,
                'required'    => false,
            ],
        ];

        if ($requireEndpoints) {
            $arguments['order']['default'] = 0;
        }

        return [
            'methods'             => $method,
            'callback'            => [ $boundary, 'updateConnection' ],
            'permission_callback' => [ $boundary, 'checkPermissions' ],
            'args'                => $arguments,
        ];
    }
}
