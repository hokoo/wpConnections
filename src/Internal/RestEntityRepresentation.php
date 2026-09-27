<?php

namespace iTRON\wpConnections\Internal;

use iTRON\wpConnections\Client;
use iTRON\wpConnections\ConnectionResolutionResult;
use iTRON\wpConnections\ResolvedEndpoint;
use iTRON\wpConnections\RestEntityAdapterInterface;
use Throwable;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

/** Permission-aware REST projection of already batch-resolved endpoints. */
final class RestEntityRepresentation
{
    private Client $client;
    private string $context;
    /** @var array<string, string[]> */
    private array $filters;
    /** @var array<string, bool> */
    private array $eligible = [];
    /** @var array<string, array> */
    private array $prepared = [];

    public function __construct(Client $client, string $context, array $filters)
    {
        $this->client = $client;
        $this->context = $context;
        $this->filters = $filters;
    }

    /**
     * Authorize unique endpoints before filtering or representation. A custom
     * adapter receives one eligibility call for its complete type group.
     *
     * @param ConnectionResolutionResult[] $rows
     */
    public function authorize(array $rows): void
    {
        $posts = [];
        $custom = [];

        foreach ($rows as $row) {
            foreach ($row->getEndpoints() as $endpoint) {
                $this->collectEndpoint($endpoint, $posts, $custom);
            }
        }

        if ([] !== $posts) {
            _prime_post_caches(
                array_map(static fn(WP_Post $post): int => $post->ID, array_values($posts)),
                true,
                true
            );
        }
        foreach ($posts as $key => $post) {
            $this->eligible[$key] = $this->canReadPost($post) && $this->matchesPost($post);
        }

        foreach ($custom as $type => $entities) {
            $this->authorizeCustomGroup($type, $entities);
        }
    }

    private function collectEndpoint(ResolvedEndpoint $endpoint, array &$posts, array &$custom): void
    {
        $entity = $endpoint->getEntity();
        $type = $endpoint->getEntityType();
        if (null === $entity || null === $type) {
            return;
        }

        $key = $this->key($endpoint);
        if (array_key_exists($key, $this->eligible)) {
            return;
        }

        $this->eligible[$key] = false;
        if ($entity instanceof WP_Post && $entity->post_type === $type) {
            $posts[$key] = $entity;
        } elseif (! post_type_exists($type)) {
            $custom[$type][$endpoint->getId()] = $entity;
        }
    }

    private function authorizeCustomGroup(string $type, array $entities): void
    {
        $adapter = $this->client->getEntityBatchResolver($type);
        if (! $adapter instanceof RestEntityAdapterInterface) {
            return;
        }

        try {
            $allowed = $adapter->getRestEligibleIds($entities, $this->filters, $this->context);
            if (! is_array($allowed)) {
                return;
            }
            foreach ($allowed as $id) {
                if (is_int($id) && isset($entities[$id])) {
                    $this->eligible[$type . ':' . $id] = true;
                }
            }
        } catch (Throwable $exception) {
            // Adapter errors share the generic unavailable representation.
        }
    }

    /** @param ConnectionResolutionResult[] $rows */
    public function matchingRows(array $rows): array
    {
        if ([] === $this->filters) {
            return $rows;
        }

        return array_values(array_filter($rows, function (ConnectionResolutionResult $row): bool {
            foreach ($row->getEndpoints() as $endpoint) {
                if ($this->eligible[$this->key($endpoint)] ?? false) {
                    return true;
                }
            }
            return false;
        }));
    }

    /** @return array<string, array> */
    public function entitiesFor(ConnectionResolutionResult $row): array
    {
        $entities = [];
        foreach ($row->getEndpoints() as $role => $endpoint) {
            $key = $this->key($endpoint);
            if (! ($this->eligible[$key] ?? false)) {
                $entities[$role] = [ 'status' => ResolvedEndpoint::UNAVAILABLE ];
                continue;
            }

            if (! isset($this->prepared[$key])) {
                $this->prepared[$key] = $this->prepare($endpoint);
            }
            $entities[$role] = $this->prepared[$key];
        }

        return $entities;
    }

    private function key(ResolvedEndpoint $endpoint): string
    {
        return (string) $endpoint->getEntityType() . ':' . $endpoint->getId();
    }

    private function canReadPost(WP_Post $post): bool
    {
        $postType = get_post_type_object($post->post_type);
        $controller = null === $postType ? null : $postType->get_rest_controller();
        if (null === $controller || ! method_exists($controller, 'get_item_permissions_check')) {
            return false;
        }

        try {
            $allowed = $controller->get_item_permissions_check($this->postRequest($post));
            return true === $allowed;
        } catch (Throwable $exception) {
            return false;
        }
    }

    private function matchesPost(WP_Post $post): bool
    {
        foreach ($this->filters as $field => $values) {
            $matched = false;
            foreach ($values as $value) {
                $matched = $matched || $this->matchesPostValue($post, $field, $value);
            }
            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    private function matchesPostValue(WP_Post $post, string $field, string $value): bool
    {
        if ('status' === $field) {
            return $post->post_status === $value;
        }
        if ('type' === $field) {
            return $post->post_type === $value;
        }
        if ('slug' === $field) {
            return $post->post_name === $value;
        }
        if (false !== stripos($post->post_title, $value)) {
            return true;
        }
        if ('' !== $post->post_password && 'edit' !== $this->context) {
            return false;
        }
        return false !== stripos($post->post_excerpt, $value) ||
            false !== stripos($post->post_content, $value);
    }

    private function prepare(ResolvedEndpoint $endpoint): array
    {
        $entity = $endpoint->getEntity();
        $type = $endpoint->getEntityType();
        try {
            if ($entity instanceof WP_Post && $entity->post_type === $type) {
                return $this->preparePost($entity);
            }
            if (null !== $type && ! post_type_exists($type)) {
                return $this->prepareCustom($entity, $type);
            }
        } catch (Throwable $exception) {
            // Do not reveal adapter/controller failure details.
        }

        return [ 'status' => ResolvedEndpoint::UNAVAILABLE ];
    }

    private function preparePost(WP_Post $post): array
    {
        $postType = get_post_type_object($post->post_type);
        $controller = null === $postType ? null : $postType->get_rest_controller();
        if (null === $controller || ! method_exists($controller, 'prepare_item_for_response')) {
            return [ 'status' => ResolvedEndpoint::UNAVAILABLE ];
        }
        $response = $controller->prepare_item_for_response($post, $this->postRequest($post));
        if (! $response instanceof WP_REST_Response) {
            return [ 'status' => ResolvedEndpoint::UNAVAILABLE ];
        }
        return [ 'status' => ResolvedEndpoint::RESOLVED, 'data' => $response->get_data() ];
    }

    private function prepareCustom(?object $entity, string $type): array
    {
        $adapter = $this->client->getEntityBatchResolver($type);
        if (! $adapter instanceof RestEntityAdapterInterface || null === $entity) {
            return [ 'status' => ResolvedEndpoint::UNAVAILABLE ];
        }
        return [
            'status' => ResolvedEndpoint::RESOLVED,
            'data' => $adapter->prepareEntityForRest($entity, $this->context),
        ];
    }

    private function postRequest(WP_Post $post): WP_REST_Request
    {
        $request = new WP_REST_Request('GET');
        $request->set_url_params([ 'id' => $post->ID ]);
        $request->set_query_params([ 'context' => $this->context ]);
        return $request;
    }
}
