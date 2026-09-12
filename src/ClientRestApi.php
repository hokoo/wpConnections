<?php

namespace iTRON\wpConnections;

use iTRON\wpConnections\Abstracts\IArrayConvertable;
use iTRON\wpConnections\Exceptions\ConnectionNotFound;
use iTRON\wpConnections\Exceptions\Exception;
use iTRON\wpConnections\Internal\RestRouteRegistry;
use iTRON\wpConnections\RestResponse\CollectionItem;
use Ramsey\Collection\Exception\NoSuchElementException;
use Ramsey\Collection\Exception\OutOfBoundsException;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;

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
        $registry = RestRouteRegistry::instance();
        if ($registry->acknowledgeActivation($this)) {
            return;
        }

        $registry->activate($this);
    }

    /**
     * Revokes this delegate's internal route mapping.
     *
     * @internal LIFE-HOOK-01 will compose this into Client::dispose().
     */
    public function deactivate(): void
    {
        RestRouteRegistry::instance()->deactivateDelegate($this);
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
        } catch (OutOfBoundsException | NoSuchElementException $e) {
            return rest_ensure_response($this->getError(new ConnectionNotFound()));
        }
    }

    public function updateConnection(WP_REST_Request $request)
    {
        $q = $this->obtainConnectionDataFromRequest($request);
        $q->set('id', $this->getRouteSelector($request, 'connectionID'));

        if (in_array($request->get_method(), [ 'POST', 'PUT' ], true)) {
            if (! $request->has_param('title')) {
                $q->set('title', null);
            }
            if (! $request->has_param('order')) {
                $q->set('order', 0);
            }
        }

        try {
            $result = $this->getClient()->getRelation(
                $this->getRouteSelector($request, 'relation')
            )->updateConnection($q);
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
        if ($q->meta->isEmpty() && $request->has_param('meta')) {
            $q->meta->fromArray((array) $request->get_param('meta'));
        }

        try {
            return $this->ensureRestResponse($this->getClient()->getRelation($request->get_param('relation'))->createConnection($q));
        } catch (Exception $e) {
            return rest_ensure_response($this->getError($e));
        }
    }

    protected function obtainConnectionDataFromRequest(WP_REST_Request $request): Query\Connection
    {
        $queryConnection = new Query\Connection();
        foreach ([ 'from', 'to', 'title', 'order' ] as $field) {
            if ($request->has_param($field)) {
                $queryConnection->set($field, $request->get_param($field));
            }
        }

        return $queryConnection;
    }

    /**
     * Route selectors remain authoritative over same-named body parameters.
     * The fallback preserves direct handler-call compatibility in PHP.
     *
     * @return mixed
     */
    private function getRouteSelector(WP_REST_Request $request, string $selector)
    {
        $urlParameters = $request->get_url_params();
        if (array_key_exists($selector, $urlParameters)) {
            return $urlParameters[ $selector ];
        }

        return $request->get_param($selector);
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
     * Rebinds the managed transport into the current REST server.
     *
     * This method remains overridable for source compatibility, but the
     * library-owned lifecycle does not invoke custom overrides.
     */
    public function registerRestRoutes()
    {
        RestRouteRegistry::instance()->rebind($this);
    }
}
