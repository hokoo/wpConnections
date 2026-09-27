<?php

namespace iTRON\wpConnections;

use iTRON\wpConnections\Abstracts\IArrayConvertable;
use iTRON\wpConnections\Exceptions\ConnectionNotFound;
use iTRON\wpConnections\Exceptions\Exception;
use iTRON\wpConnections\Internal\RestErrorResponder;
use iTRON\wpConnections\Internal\RestEntityRepresentation;
use iTRON\wpConnections\Internal\RestRouteRegistry;
use iTRON\wpConnections\RestResponse\CollectionItem;
use iTRON\wpConnections\RestResponse\ExpandedCollectionItem;
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
        $this->getClient()->assertIntegrationLifecycleActive();
        $registry = RestRouteRegistry::instance();
        if ($registry->acknowledgeActivation($this)) {
            return;
        }

        $registry->activate($this);
    }

    /**
     * Revokes this delegate's internal route mapping.
     *
     * @internal Client::dispose() composes this into the owned-integration teardown.
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
        $bodyParameters = $request->get_body_params();
        $jsonParameters = $request->get_json_params();
        foreach ([ 'target', 'representation', 'entity', 'context', 'page', 'per_page' ] as $parameter) {
            if (
                array_key_exists($parameter, $bodyParameters)
                || (is_array($jsonParameters) && array_key_exists($parameter, $jsonParameters))
            ) {
                return $this->invalidRelationParameter($parameter);
            }
        }

        $query = new Query\Connection();
        $queryParameters = $request->get_query_params();
        $unknown = array_diff(
            array_keys($queryParameters),
            [
                'client', 'relation', 'from', 'to', 'both', 'target', 'representation',
                'entity', 'context', 'page', 'per_page', '_fields', '_embed',
                '_links', '_envelope', '_jsonp', '_locale', 'rest_route',
            ]
        );
        if ([] !== $unknown) {
            return $this->invalidRelationParameter((string) reset($unknown));
        }

        foreach ([ 'from', 'to', 'both' ] as $selector) {
            if (array_key_exists($selector, $queryParameters)) {
                $query->set($selector, (int) $queryParameters[ $selector ]);
            }
        }

        try {
            $relation = $this->getClient()->getRelation($this->getRouteSelector($request, 'relation'));
            $filters = $this->relationEntityFilters($queryParameters);
            $target = $this->relationEndpointTarget($queryParameters);
            if ([] !== $filters && null === $target) {
                return $this->invalidRelationParameter('entity');
            }
            if ([] !== $filters && ! $this->supportsRelationEntityFilters($relation, $filters, $queryParameters)) {
                return $this->invalidRelationParameter('entity');
            }

            $expanded = 'expanded' === ($queryParameters['representation'] ?? null);
            $paginated = isset($queryParameters['page']) || isset($queryParameters['per_page']);
            $context = $queryParameters['context'] ?? 'view';
            $connections = $relation->findConnections($query);
            if (! $expanded && ! $paginated && [] === $filters) {
                $response = [];
                foreach ($connections->getIterator() as $connectionItem) {
                    $response[] = $this->getRestConnectionItem($connectionItem);
                }
                return rest_ensure_response($response);
            }

            $items = iterator_to_array($connections->getIterator(), false);
            usort($items, static function (Connection $left, Connection $right): int {
                return [ $left->order, $left->id ] <=> [ $right->order, $right->id ];
            });
            $page = (int) ($queryParameters['page'] ?? 1);
            $perPage = (int) ($queryParameters['per_page'] ?? 20);
            $total = count($items);
            if ([] === $filters) {
                if ($paginated) {
                    $items = array_slice($items, ($page - 1) * $perPage, $perPage);
                }
                if (! $expanded || null === $target) {
                    return $this->relationResponse(
                        $this->renderConnectionItems($items, $expanded),
                        $paginated,
                        $total,
                        $perPage
                    );
                }
            }
            $results = (new ConnectionCollection($items))->resolveEntities(
                $target
            );
            $projection = new RestEntityRepresentation($this->getClient(), $context, $filters);
            if ([] !== $filters) {
                $projection->authorize($results);
                $results = $projection->matchingRows($results);
            }
            if ([] !== $filters) {
                $total = count($results);
            }
            if ($paginated && [] !== $filters) {
                $results = array_slice($results, ($page - 1) * $perPage, $perPage);
            }
            if ($expanded) {
                $projection->authorize($results);
            }
            $response = [];
            foreach ($results as $row) {
                $connectionItem = $row->getConnection();
                if ($expanded) {
                    $item = new ExpandedCollectionItem($connectionItem);
                    $item->add_link('self', $this->getRestConnectionUrl($connectionItem->relation, $connectionItem->id));
                    $item->entities = null === $target ? [] : $projection->entitiesFor($row);
                    $response[] = $item;
                } else {
                    $response[] = $this->getRestConnectionItem($connectionItem);
                }
            }
        } catch (Exception $e) {
            return rest_ensure_response($this->getError($e));
        }

        return $this->relationResponse($response, $paginated, $total, $perPage);
    }

    private function relationResponse(array $response, bool $paginated, int $total, int $perPage): WP_REST_Response
    {
        $restResponse = rest_ensure_response($response);
        if ($paginated) {
            $restResponse->header('X-WP-Total', (string) $total);
            $restResponse->header('X-WP-TotalPages', (string) (int) ceil($total / $perPage));
        }
        return $restResponse;
    }

    /** @param Connection[] $connections */
    private function renderConnectionItems(array $connections, bool $expanded): array
    {
        $response = [];
        foreach ($connections as $connection) {
            if ($expanded) {
                $item = new ExpandedCollectionItem($connection);
                $item->add_link('self', $this->getRestConnectionUrl($connection->relation, $connection->id));
                $response[] = $item;
            } else {
                $response[] = $this->getRestConnectionItem($connection);
            }
        }
        return $response;
    }

    /** @return array<string, string[]> */
    private function relationEntityFilters(array $query): array
    {
        $filters = [];
        foreach ($query['entity'] ?? [] as $field => $values) {
            $filters[$field] = array_map('trim', is_array($values) ? $values : [ $values ]);
        }
        return $filters;
    }

    private function relationEndpointTarget(array $query): ?EndpointTarget
    {
        $requested = $query['target'] ?? null;
        if ('from' === $requested) {
            return EndpointTarget::from();
        }
        if ('to' === $requested) {
            return EndpointTarget::to();
        }
        if ('both' === $requested) {
            return EndpointTarget::both();
        }
        if ('opposite' === $requested) {
            $selector = isset($query['from']) ? 'from' : (isset($query['to']) ? 'to' : 'both');
            return EndpointTarget::opposite($selector, (int) $query[$selector]);
        }
        return null;
    }

    private function supportsRelationEntityFilters(
        Relation $relation,
        array $filters,
        array $query
    ): bool {
        $requested = $query['target'];
        $roles = [ 'from', 'to' ];
        if ('from' === $requested || 'to' === $requested) {
            $roles = [ $requested ];
        } elseif ('opposite' === $requested && isset($query['from'])) {
            $roles = [ 'to' ];
        } elseif ('opposite' === $requested && isset($query['to'])) {
            $roles = [ 'from' ];
        }

        foreach ($roles as $role) {
            $type = $relation->{$role};
            if (post_type_exists($type)) {
                continue;
            }
            $adapter = $this->getClient()->getEntityBatchResolver($type);
            if (! $adapter instanceof RestEntityAdapterInterface) {
                return false;
            }
            try {
                $supported = $adapter->getSupportedRestFilters();
            } catch (\Throwable $exception) {
                return false;
            }
            if ([] !== array_diff(array_keys($filters), $supported)) {
                return false;
            }
        }
        return true;
    }

    private function invalidRelationParameter(string $parameter): WP_Error
    {
        return new WP_Error(
            'rest_invalid_param',
            sprintf(__('Invalid parameter(s): %s'), $parameter),
            [ 'status' => 400, 'params' => [ $parameter => __('Invalid parameter.') ] ]
        );
    }

    public function getConnection(WP_REST_Request $request)
    {
        $q = new Query\Connection();
        $q->set('id', $this->getRouteSelector($request, 'connectionID'));
        try {
            return $this->ensureRestResponse(
                $this->getClient()->getRelation(
                    $this->getRouteSelector($request, 'relation')
                )->findConnections($q)->first()
            );
        } catch (Exception $e) {
            return rest_ensure_response($this->getError($e));
        } catch (OutOfBoundsException | NoSuchElementException $e) {
            return rest_ensure_response($this->getError(new ConnectionNotFound()));
        }
    }

    public function updateConnection(WP_REST_Request $request)
    {
        $scalarRequest = clone $request;
        unset($scalarRequest['meta']);
        $q = $this->obtainConnectionDataFromRequest($scalarRequest);
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
            $queryConnection->id = $this->getRouteSelector($request, 'connectionID');

            $found = $this->getClient()->getRelation(
                $this->getRouteSelector($request, 'relation')
            )->findConnections($queryConnection);

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
        $queryConnection->set('id', $this->getRouteSelector($request, 'connectionID'));
        $queryConnection->meta->fromArray((array) $request->get_param('meta'));

        try {
            $relation = $this->getClient()->getRelation(
                $this->getRouteSelector($request, 'relation')
            );
            if ($relation->findConnections($queryConnection)->isEmpty()) {
                throw new ConnectionNotFound();
            }

            $result = $relation->removeConnectionMeta($queryConnection);
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
        $q->set('id', $this->getRouteSelector($request, 'connectionID'));

        try {
            $rows = $this->getClient()->getRelation(
                $this->getRouteSelector($request, 'relation')
            )->detachConnections($q);
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
            return $this->ensureRestResponse(
                $this->getClient()->getRelation(
                    $this->getRouteSelector($request, 'relation')
                )->createConnection($q)
            );
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

        if ($request->has_param('meta')) {
            $queryConnection->meta->fromArray((array) $request->get_param('meta'));
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
        return RestErrorResponder::fromThrowable($this->getClient(), $exception);
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
        $this->getClient()->assertIntegrationLifecycleActive();
        RestRouteRegistry::instance()->rebind($this);
    }
}
