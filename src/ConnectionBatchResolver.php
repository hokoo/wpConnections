<?php

namespace iTRON\wpConnections;

use Throwable;
use WP_Post;

/** Resolves one collection with a call-local lookup map. */
final class ConnectionBatchResolver
{
    /**
     * @return ConnectionResolutionResult[] One result per input occurrence.
     */
    public function resolve(ConnectionCollection $connections, EndpointTarget $target): array
    {
        $rows = [];
        $groups = [];

        foreach ($connections->getIterator() as $connection) {
            $endpoints = [];
            $client = null;
            $relation = null;

            try {
                $client = $connection->getClient();
                if ($client->isCurrentEntityResolutionContext()) {
                    $relation = $client->getRelation($connection->relation);
                }
            } catch (Throwable $exception) {
                // An unhydrated or obsolete relation retains unavailable slots.
            }

            foreach ($target->roles($connection) as $role) {
                $id = $connection->{$role};
                $type = null === $relation ? null : $relation->{$role};
                $endpoints[$role] = [ 'id' => $id, 'type' => $type, 'group' => null ];

                if (null === $relation || 0 >= $id || ! is_string($type) || '' === $type) {
                    continue;
                }

                $isPost = post_type_exists($type);
                if ($isPost && $client->hasEntityResolver($type)) {
                    continue;
                }

                $adapter = $isPost ? null : $client->getEntityBatchResolver($type);
                if (! $isPost && null === $adapter) {
                    continue;
                }

                $key = spl_object_id($client) . ':' . $type;
                if (! isset($groups[$key])) {
                    $groups[$key] = [
                        'type' => $type,
                        'adapter' => $adapter,
                        'ids' => [],
                    ];
                }

                $groups[$key]['ids'][$id] = $id;
                $endpoints[$role]['group'] = $key;
            }

            $rows[] = [ 'connection' => $connection, 'endpoints' => $endpoints ];
        }

        $resolved = [];
        foreach ($groups as $key => $group) {
            $ids = array_values($group['ids']);
            try {
                $entities = null === $group['adapter']
                    ? $this->resolvePostsMany($ids, $group['type'])
                    : $group['adapter']->resolveMany($ids, $group['type']);
            } catch (Throwable $exception) {
                $entities = [];
            }

            $resolved[$key] = [];
            foreach ($ids as $id) {
                if (isset($entities[$id]) && is_object($entities[$id])) {
                    $resolved[$key][$id] = $entities[$id];
                }
            }
        }

        $results = [];
        foreach ($rows as $row) {
            $endpoints = [];
            foreach ($row['endpoints'] as $role => $endpoint) {
                $key = $endpoint['group'];
                $id = $endpoint['id'];
                $entity = null === $key ? null : ($resolved[$key][$id] ?? null);
                $endpoints[$role] = new ResolvedEndpoint($role, $id, $endpoint['type'], $entity);
            }

            $results[] = new ConnectionResolutionResult($row['connection'], $endpoints);
        }

        return $results;
    }

    /**
     * Primes found posts in one WordPress query. Inspecting the cache first
     * prevents a separate get_post() query for every missing legacy ID.
     *
     * @param int[] $ids
     * @return array<int, WP_Post>
     */
    private function resolvePostsMany(array $ids, string $type): array
    {
        _prime_post_caches($ids, false, false);

        $posts = [];
        foreach ($ids as $id) {
            if (false === wp_cache_get($id, 'posts')) {
                continue;
            }

            $post = get_post($id);
            if ($post instanceof WP_Post && $post->post_type === $type) {
                $posts[$id] = $post;
            }
        }

        return $posts;
    }
}
