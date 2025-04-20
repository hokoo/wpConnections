<?php

namespace iTRON\wpConnections;

use iTRON\wpConnections\Abstracts\IArrayConvertable;
use iTRON\wpConnections\Abstracts\IQuery;
use iTRON\wpConnections\Exceptions\ClientRegisterFail;
use iTRON\wpConnections\Exceptions\ConnectionNotFound;
use iTRON\wpConnections\Exceptions\Exception;
use iTRON\wpConnections\Exceptions\RelationNotFound;
use iTRON\wpConnections\RestResponse\CollectionItem;
use Ramsey\Collection\Exception\OutOfBoundsException;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class ClientRestApi
{
    use ClientInterface;

    public string $namespace = 'wp-connections/v1';
    public string $base = 'client';

    public function __construct(Client $client)
    {
        $this->setClient($client);
    }

    public function init()
    {
        add_action('rest_api_init', [ $this, 'registerRestRoutes' ], 10);
    }

    public function getTheClient(WP_REST_Request $request)
    {
        $relations = [];
        foreach ($this->getClient()->getRelations()->getIterator() as $relationItem) {
            /** @var Relation $relationItem */
            $relation = $this->ensureRestResponseCollectionItem($relationItem);
            $relation->add_link('self', $this->getRestRelationUrl($relationItem->get('name')));
            $relations [] = $relation;
        }

        return rest_ensure_response($relations);
    }

    public function getRelation(WP_REST_Request $request)
    {
        try {
            $response = [];
            foreach ($this->getClient()->getRelation($request->get_param('relation'))->findConnections()->getIterator() as $connectionItem) {
                /** @var Connection $connectionItem */
                $response [] = $this->getRestConnectionItem($connectionItem);
            }
        } catch (Exception $e) {
            return rest_ensure_response($this->getError($e));
        }

        return rest_ensure_response($response);
    }

    public function getConnection(WP_REST_Request $request)
    {
        $q = new Query\Connection();
        $q->set('id', $request->get_param('connectionID'));
        try {
            return $this->ensureRestResponse(
                $this->getClient()->getRelation($request->get_param('relation'))->findConnections($q)->first()
            );
        } catch (Exception $e) {
            return rest_ensure_response($this->getError($e));
        } catch (OutOfBoundsException $e) {
            return rest_ensure_response($this->getError(new ConnectionNotFound()));
        }
    }

    public function updateConnection(WP_REST_Request $request)
    {
        $q = $this->obtainConnectionDataFromRequest($request);
        $q->set('id', $request->get_param('connectionID'));

        try {
            $result = $this->getClient()->getRelation($request->get_param('relation'))->updateConnection($q);
        } catch (Exception $e) {
            return rest_ensure_response($this->getError($e));
        }

        return [ 'updated' => $result ];
    }

    /**
     * POST means nothing to delete, add new meta only.
     * PATCH means removing if key already exists and then adding new meta fields.
     * PUT means erasing all existing metadata and put the new fields.
     *
     * @param WP_REST_Request $request
     *
     * @return array|mixed|WP_Error|WP_HTTP_Response|WP_REST_Response
     */
    public function updateConnectionMeta(WP_REST_Request $request)
    {
        try {
            $queryConnection = new Query\Connection();
            $queryConnection->id = $request->get_param('connectionID');

            $found = $this->getClient()->getRelation($request->get_param('relation'))->findConnections($queryConnection);

            if ($found->isEmpty()) {
                return rest_ensure_response($this->getError(new ConnectionNotFound()));
            }

            $connection = $found->first();

            if ('PUT' === $request->get_method()) {
                $connection->meta->clear();
            }

            if ('PATCH' === $request->get_method()) {
                $filtered_meta = $connection->meta->filter(function (Meta $meta) use ($request) {
                    return ! in_array($meta->getKey(), array_column($request->get_param('meta'), 'key'));
                });

                $connection->meta->clear();
                $connection->meta->fromArray($filtered_meta->toArray());
            }

            $connection->meta->fromArray((array) $request->get_param('meta'));

            $connection->update();
        } catch (Exception $e) {
            return rest_ensure_response($this->getError($e));
        }

        return rest_ensure_response([ 'updated' => $connection ]);
    }

    public function deleteConnectionMeta(WP_REST_Request $request)
    {
        $queryConnection = new Query\Connection();
        $queryConnection->set('id', $request->get_param('connectionID'));
        $queryConnection->meta->fromArray((array) $request->get_param('meta'));

        try {
            $result = $this->getClient()->getRelation($request->get_param('relation'))->removeConnectionMeta($queryConnection);
        } catch (Exception $e) {
            return rest_ensure_response($this->getError($e));
        }

        return [ 'deleted' => $result ];
    }


    /**
     * @param WP_REST_Request $request
     * @return bool
     */
    public function checkPermissions(WP_REST_Request $request): bool
    {
        $callback = $request->get_attributes()['callback'][1] ?? '';
        return current_user_can($this->getClient()->capabilities->{$callback});
    }

    public function deleteConnection(WP_REST_Request $request)
    {
        $q = new Query\Connection();
        $q->set('id', $request->get_param('connectionID'));

        try {
            $rows = $this->getClient()->getRelation($request->get_param('relation'))->detachConnections($q);
        } catch (Exception $e) {
            return rest_ensure_response($this->getError($e));
        }

        if (0 === (int) $rows) {
            return rest_ensure_response($this->getError(new ConnectionNotFound()));
        }

        return rest_ensure_response([ 'deleted'  => true ]);
    }

    public function createConnection(WP_REST_Request $request)
    {
        $q = $this->obtainConnectionDataFromRequest($request);

        try {
            return $this->ensureRestResponse($this->getClient()->getRelation($request->get_param('relation'))->createConnection($q));
        } catch (Exception $e) {
            return rest_ensure_response($this->getError($e));
        }
    }

    protected function obtainConnectionDataFromRequest(WP_REST_Request $request): Query\Connection
    {
        $queryConnection = new Query\Connection();
        foreach ($request->get_params() as $key => $value) {
            if (property_exists($queryConnection, $key) && ! is_null($value) && 'meta' != $key) {
                $queryConnection->set($key, $value);
            }
        }

        $queryConnection->meta->fromArray((array) $request->get_param('meta'));

        return $queryConnection;
    }

    protected function getRestConnectionItem(Connection $connection): CollectionItem
    {
        $response = $this->ensureRestResponseCollectionItem($connection);
        $response->add_link('self', $this->getRestConnectionUrl($connection->relation, $connection->id));
        return $response;
    }

    protected function ensureRestResponseCollectionItem(IArrayConvertable $data): CollectionItem
    {
        if ($data instanceof CollectionItem) {
            return $data;
        }

        return new CollectionItem($data);
    }

    protected function ensureRestResponse(IArrayConvertable $data)
    {
        return rest_ensure_response($data->toArray());
    }

    protected function getError(\Exception $exception): WP_Error
    {
        return new WP_Error($exception->getCode(), $exception->getMessage());
    }

    protected function getRestBaseUrl(): string
    {
        return rest_url($this->namespace . '/' . $this->base);
    }

    protected function getRestClientUrl(): string
    {
        return $this->getRestBaseUrl() . '/' . $this->getClient()->getName();
    }

    protected function getRestRelationUrl(string $relationName): string
    {
        return $this->getRestClientUrl() . '/relation/' . $relationName;
    }

    protected function getRestConnectionUrl(string $relationName, $connectionID): string
    {
        return $this->getRestRelationUrl($relationName) . '/' . $connectionID;
    }

    /**
     * @throws ClientRegisterFail
     */
    public function registerRestRoutes()
    {
        $result = [];
        $result [] = register_rest_route(
            $this->namespace,
            '/' . $this->base . '/' . $this->getClient()->getName(),
            [
                'args'   => [],
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'description'         => 'Get the client relations.',
                    'callback'            => [ $this, 'getTheClient' ],
                    'permission_callback' => [ $this, 'checkPermissions' ],
                ],
            ]
        );

        $result [] = register_rest_route(
            $this->namespace,
            '/' . $this->base . '/' . $this->getClient()->getName() .
            '/relation/' . '(?P<relation>[\w-]+)',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'getRelation' ],
                    'permission_callback' => [ $this, 'checkPermissions' ],
                    'args'   => [
                        'relation' => [
                            'description' => __('Unique name for the relation.'),
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
                            'description' => __('Post ID that is considered as FROM.'),
                            'type'        => 'integer',
                            'required'    => true,
                        ],
                        'to'  => [
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
                        'meta'  => [
                            'description' => __('Connection meta data.'),
                            'type'        => 'array',
                            'required'    => false,
                            'default'     => [],
                        ]
                    ]
                ],
            ]
        );

        $result [] = register_rest_route(
            $this->namespace,
            '/' . $this->base . '/' . $this->getClient()->getName() .
            '/relation/' . '(?P<relation>[\w-]+)' .
            '/(?P<connectionID>[\d]+)',
            [
                'args'   => [
                    'relation' => [
                        'description' => __('Unique name for the relation.'),
                        'type'        => 'string',
                        'required'    => true,
                    ],
                ],
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'getConnection' ],
                    'permission_callback' => [ $this, 'checkPermissions' ],
                    'args' => [
                        'connectionID' => [
                            'description' => __('Connection ID to be retrieved.'),
                            'type'        => 'integer',
                            'required'    => true,
                        ],
                    ]
                ],
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'updateConnection' ],
                    'permission_callback' => [ $this, 'checkPermissions' ],
                    'args' => [
                        'connectionID' => [
                            'type'        => 'integer',
                            'required'    => true,
                        ],
                        'from'  => [
                            'description' => __('Post ID that is considered as FROM.'),
                            'type'        => 'integer',
                            'required'    => true,
                        ],
                        'to'  => [
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
                    ]
                ],
                [
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => [ $this, 'deleteConnection' ],
                    'permission_callback' => [ $this, 'checkPermissions' ],
                    'args' => [
                        'connectionID' => [
                            'description' => __('Connection ID to be removed.'),
                            'type'        => 'integer',
                            'required'    => true,
                        ],
                    ]
                ],
            ]
        );

        $result [] = register_rest_route(
            $this->namespace,
            '/' . $this->base . '/' . $this->getClient()->getName() .
            '/relation/' . '(?P<relation>[\w-]+)' .
            '/(?P<connectionID>[\d]+)' .
            '/meta',
            [
                'args'   => [
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
                    'callback'            => [ $this, 'updateConnectionMeta' ],
                    'permission_callback' => [ $this, 'checkPermissions' ],
                    'args' => [
                        'meta'  => [
                            'description' => __('Add connection meta data.'),
                            'type'        => 'array',
                            'required'    => false,
                            'default'     => [],
                        ],
                    ]
                ],
                [
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => [ $this, 'deleteConnectionMeta' ],
                    'permission_callback' => [ $this, 'checkPermissions' ],
                    'args' => [
                        'connectionID' => [
                            'description' => __('Connection ID of the meta to be removed.'),
                            'type'        => 'integer',
                            'required'    => true,
                        ],
                    ]
                ],
            ]
        );

        if (in_array(false, $result, true)) {
            throw new ClientRegisterFail('An error has occurred during REST API Routes registering.');
        }
    }
}
