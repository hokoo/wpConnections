<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\BatchEntityResolverInterface;
use iTRON\wpConnections\Client;
use iTRON\wpConnections\Connection;
use iTRON\wpConnections\ConnectionCollection;
use iTRON\wpConnections\EndpointTarget;
use iTRON\wpConnections\EntityResolution;
use iTRON\wpConnections\EntityResolverInterface;
use iTRON\wpConnections\Exceptions\ClientRegisterFail;
use iTRON\wpConnections\Query\Connection as ConnectionQuery;
use iTRON\wpConnections\Query\Relation as RelationQuery;
use iTRON\wpConnections\ResolvedEndpoint;

class ConnectionBatchResolutionTest extends WPConnectionsTestCase
{
    public function test_get_posts_preserves_occurrences_and_omits_unavailable_posts(): void
    {
        $connections = new ConnectionCollection([
            $this->connection(1, $this->page_ids[0], $this->post_ids[0]),
            $this->connection(2, $this->page_ids[0], 987654321),
            $this->connection(3, $this->page_ids[0], $this->post_ids[0]),
        ]);

        self::assertSame(
            [ $this->post_ids[0], $this->post_ids[0] ],
            array_map(static fn(\WP_Post $post): int => $post->ID, $connections->getPosts('to'))
        );
        self::assertSame([], (new ConnectionCollection())->getPosts('from'));
        $this->expectException(\InvalidArgumentException::class);
        (new ConnectionCollection())->getPosts('both');
    }

    public function test_projection_keeps_roles_rows_duplicates_and_generic_unavailable_slots(): void
    {
        $self = $this->connection(4, $this->post_ids[0], $this->post_ids[0], 'batch-posts');
        $this->registerRelation('batch-posts', 'post', 'post');
        $rows = new ConnectionCollection([
            $this->connection(1, $this->page_ids[0], $this->post_ids[0]),
            $this->connection(2, $this->page_ids[0], 987654321),
            $this->connection(3, $this->page_ids[0], $this->post_ids[0]),
            $self,
        ]);

        $both = $rows->resolveEntities(EndpointTarget::both());
        self::assertSame([1, 2, 3, 4], array_map(
            static fn($row): int => $row->getConnection()->id,
            $both
        ));
        self::assertSame([ 'from', 'to' ], array_keys($both[0]->getEndpoints()));
        self::assertSame($this->post_ids[0], $both[0]->getEndpoints()['to']->getId());
        self::assertSame($this->post_ids[0], $both[2]->getEndpoints()['to']->getId());
        self::assertSame(ResolvedEndpoint::UNAVAILABLE, $both[1]->getEndpoints()['to']->getStatus());
        self::assertSame([ 'from', 'to' ], array_keys($both[3]->getEndpoints()));
        self::assertSame($this->post_ids[0], $both[3]->getEndpoints()['from']->getId());
        self::assertSame($this->post_ids[0], $both[3]->getEndpoints()['to']->getId());

        $opposite = $rows->resolveEntities(EndpointTarget::opposite('both', $this->post_ids[0]));
        self::assertSame([ 'from' ], array_keys($opposite[0]->getEndpoints()));
        self::assertSame([], $opposite[1]->getEndpoints());
        self::assertSame([], $opposite[3]->getEndpoints());

        $absolute = $rows->resolveEntities(EndpointTarget::from());
        self::assertSame([ 'from' ], array_keys($absolute[0]->getEndpoints()));
    }

    public function test_non_post_companion_batches_once_and_does_not_cross_clients(): void
    {
        $this->registerRelation('batch-custom', 'batch_record', 'post');
        $this->registerRelation('batch-legacy', 'batch_legacy', 'post');
        $mutation = new class() implements EntityResolverInterface {
            public int $calls = 0;

            public function getSupportedEntityTypes(): array
            {
                return [ 'batch_record', 'batch_legacy' ];
            }

            public function resolve(int $entityId, string $entityType): EntityResolution
            {
                ++$this->calls;
                return EntityResolution::accepted();
            }
        };
        $batch = new class() implements BatchEntityResolverInterface {
            public array $calls = [];

            public function resolveMany(array $entityIds, string $entityType): array
            {
                $this->calls[] = [ $entityIds, $entityType ];
                return [ 7 => (object) [ 'id' => 7 ] ];
            }
        };
        $this->client->registerEntityResolver($mutation);
        $this->client->registerEntityBatchResolver('batch_record', $batch);

        $skipInstall = static fn(): bool => false;
        add_filter('wpConnections/storage/installOnInit', $skipInstall, 20);
        try {
            $second = new Client('batch-second');
        } finally {
            remove_filter('wpConnections/storage/installOnInit', $skipInstall, 20);
        }
        try {
            $relation = new RelationQuery();
            $relation->set('name', 'batch-custom')->set('from', 'batch_record')->set('to', 'post');
            $second->registerRelation($relation);

            $other = $this->connection(5, 7, $this->post_ids[0], 'batch-custom', $second);
            $rows = new ConnectionCollection([
                $this->connection(1, 7, $this->post_ids[0], 'batch-custom'),
                $this->connection(2, 8, $this->post_ids[0], 'batch-custom'),
                $this->connection(3, 7, $this->post_ids[1], 'batch-custom'),
                $other,
                $this->connection(6, 9, $this->post_ids[0], 'batch-legacy'),
            ]);
            $results = $rows->resolveEntities(EndpointTarget::from());

            self::assertSame([ [ [7, 8], 'batch_record' ] ], $batch->calls);
            self::assertSame(0, $mutation->calls);
            self::assertSame(ResolvedEndpoint::RESOLVED, $results[0]->getEndpoints()['from']->getStatus());
            self::assertSame(ResolvedEndpoint::UNAVAILABLE, $results[1]->getEndpoints()['from']->getStatus());
            self::assertSame(ResolvedEndpoint::RESOLVED, $results[2]->getEndpoints()['from']->getStatus());
            self::assertSame(ResolvedEndpoint::UNAVAILABLE, $results[3]->getEndpoints()['from']->getStatus());
            self::assertSame(ResolvedEndpoint::UNAVAILABLE, $results[4]->getEndpoints()['from']->getStatus());
            self::assertSame($other, $results[3]->getConnection());
            self::assertSame(7, $results[0]->getEndpoints()['from']->getId());
            self::assertSame(7, $results[3]->getEndpoints()['from']->getId());
            self::assertSame([], $rows->getPosts('from'));
        } finally {
            $second->dispose();
        }
    }

    public function test_throwing_adapter_only_unavailable_for_its_own_group(): void
    {
        $this->registerRelation('batch-fails', 'batch_fail', 'post');
        $this->registerRelation('batch-works', 'batch_good', 'post');
        $this->registerRelation('batch-posts', 'post', 'post');
        $mutation = new class() implements EntityResolverInterface {
            public int $calls = 0;

            public function getSupportedEntityTypes(): array
            {
                return [ 'batch_fail', 'batch_good' ];
            }

            public function resolve(int $entityId, string $entityType): EntityResolution
            {
                ++$this->calls;
                return EntityResolution::accepted();
            }
        };
        $this->client->registerEntityResolver($mutation);
        $failed = new class() implements BatchEntityResolverInterface {
            public int $calls = 0;

            public function resolveMany(array $entityIds, string $entityType): array
            {
                ++$this->calls;
                throw new \RuntimeException('adapter failed');
            }
        };
        $good = new class() implements BatchEntityResolverInterface {
            public int $calls = 0;

            public function resolveMany(array $entityIds, string $entityType): array
            {
                ++$this->calls;
                return [ 7 => (object) [ 'id' => 7 ] ];
            }
        };
        $this->client->registerEntityBatchResolver('batch_fail', $failed);
        $this->client->registerEntityBatchResolver('batch_good', $good);
        $rows = new ConnectionCollection([
            $this->connection(1, 7, $this->post_ids[0], 'batch-fails'),
            $this->connection(2, 7, $this->post_ids[0], 'batch-works'),
            $this->connection(3, $this->post_ids[0], $this->post_ids[1], 'batch-posts'),
        ]);

        $results = $rows->resolveEntities(EndpointTarget::from());
        self::assertSame(ResolvedEndpoint::UNAVAILABLE, $results[0]->getEndpoints()['from']->getStatus());
        self::assertSame(ResolvedEndpoint::RESOLVED, $results[1]->getEndpoints()['from']->getStatus());
        self::assertSame(ResolvedEndpoint::RESOLVED, $results[2]->getEndpoints()['from']->getStatus());
        self::assertSame(1, $failed->calls);
        self::assertSame(1, $good->calls);
        self::assertSame(0, $mutation->calls);
    }

    public function test_batch_companion_preserves_type_ownership_and_registration_lock(): void
    {
        $mutation = new class() implements EntityResolverInterface {
            public function getSupportedEntityTypes(): array
            {
                return [ 'batch_owner', 'batch_late' ];
            }

            public function resolve(int $entityId, string $entityType): EntityResolution
            {
                return EntityResolution::accepted();
            }
        };
        $batch = new class() implements BatchEntityResolverInterface {
            public function resolveMany(array $entityIds, string $entityType): array
            {
                return [];
            }
        };
        $this->client->registerEntityResolver($mutation);

        foreach ([ 'post', 'unknown_type' ] as $type) {
            try {
                $this->client->registerEntityBatchResolver($type, $batch);
                self::fail('A batch companion claimed an unowned type.');
            } catch (ClientRegisterFail $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }

        $this->client->registerEntityBatchResolver('batch_owner', $batch);
        try {
            $this->client->registerEntityBatchResolver('batch_owner', $batch);
            self::fail('A second batch companion replaced the first.');
        } catch (ClientRegisterFail $exception) {
            self::assertNotSame('', $exception->getMessage());
        }

        $this->client->getRelation(RELATION_0_NAME)->createConnection(
            new ConnectionQuery($this->page_ids[0], $this->post_ids[0])
        );
        try {
            $this->client->registerEntityBatchResolver('batch_late', $batch);
            self::fail('A batch companion registered after the first mutation.');
        } catch (ClientRegisterFail $exception) {
            self::assertSame(
                'Entity resolvers must be registered before the first connection mutation.',
                $exception->getMessage()
            );
        }
    }

    public function test_late_post_type_collision_with_mutation_only_resolver_is_unavailable(): void
    {
        $type = 'batch_collision';
        $this->registerRelation('batch-collision', $type, 'post');
        $mutation = new class($type) implements EntityResolverInterface {
            private string $type;

            public function __construct(string $type)
            {
                $this->type = $type;
            }

            public function getSupportedEntityTypes(): array
            {
                return [ $this->type ];
            }

            public function resolve(int $entityId, string $entityType): EntityResolution
            {
                return EntityResolution::accepted();
            }
        };
        $this->client->registerEntityResolver($mutation);
        register_post_type($type, [ 'public' => false ]);

        try {
            $postId = self::factory()->post->create([ 'post_type' => $type ]);
            $rows = new ConnectionCollection([
                $this->connection(1, $postId, $this->post_ids[0], 'batch-collision'),
                $this->connection(2, $this->page_ids[0], $this->post_ids[0]),
            ]);
            $results = $rows->resolveEntities(EndpointTarget::from());

            self::assertSame(ResolvedEndpoint::UNAVAILABLE, $results[0]->getEndpoints()['from']->getStatus());
            self::assertSame(ResolvedEndpoint::RESOLVED, $results[1]->getEndpoints()['from']->getStatus());
            self::assertSame(
                [ $this->page_ids[0] ],
                array_map(static fn(\WP_Post $post): int => $post->ID, $rows->getPosts('from'))
            );

            $batch = new class() implements BatchEntityResolverInterface {
                public int $calls = 0;

                public function resolveMany(array $entityIds, string $entityType): array
                {
                    ++$this->calls;
                    return [ $entityIds[0] => (object) [ 'id' => $entityIds[0] ] ];
                }
            };
            try {
                $this->client->registerEntityBatchResolver($type, $batch);
                self::fail('A batch companion claimed a registered WordPress post type.');
            } catch (ClientRegisterFail $exception) {
                self::assertNotSame('', $exception->getMessage());
            }

            unregister_post_type($type);
            $this->client->registerEntityBatchResolver($type, $batch);
            register_post_type($type, [ 'public' => false ]);
            self::assertSame(
                ResolvedEndpoint::UNAVAILABLE,
                $rows->resolveEntities(EndpointTarget::from())[0]->getEndpoints()['from']->getStatus()
            );
            self::assertSame(0, $batch->calls);
        } finally {
            unregister_post_type($type);
        }
    }

    public function test_late_post_type_collision_with_inline_batch_resolver_is_unavailable(): void
    {
        $type = 'batch_inline';
        $this->registerRelation('batch-inline', $type, 'post');
        $resolver = new class($type) implements EntityResolverInterface, BatchEntityResolverInterface {
            private string $type;
            public int $calls = 0;

            public function __construct(string $type)
            {
                $this->type = $type;
            }

            public function getSupportedEntityTypes(): array
            {
                return [ $this->type ];
            }

            public function resolve(int $entityId, string $entityType): EntityResolution
            {
                return EntityResolution::accepted();
            }

            public function resolveMany(array $entityIds, string $entityType): array
            {
                ++$this->calls;
                return [ $entityIds[0] => (object) [ 'id' => $entityIds[0] ] ];
            }
        };
        $this->client->registerEntityResolver($resolver);
        register_post_type($type, [ 'public' => false ]);

        try {
            $postId = self::factory()->post->create([ 'post_type' => $type ]);
            $rows = new ConnectionCollection([
                $this->connection(1, $postId, $this->post_ids[0], 'batch-inline'),
                $this->connection(2, $this->page_ids[0], $this->post_ids[0]),
            ]);
            $results = $rows->resolveEntities(EndpointTarget::from());

            self::assertSame(ResolvedEndpoint::UNAVAILABLE, $results[0]->getEndpoints()['from']->getStatus());
            self::assertSame(ResolvedEndpoint::RESOLVED, $results[1]->getEndpoints()['from']->getStatus());
            self::assertSame(0, $resolver->calls);
        } finally {
            unregister_post_type($type);
        }
    }

    public function test_hydrated_collection_resolves_and_rejects_wrong_post_type(): void
    {
        $this->client->getRelation(RELATION_0_NAME)->createConnection(
            new ConnectionQuery($this->page_ids[0], $this->post_ids[0])
        );
        $hydrated = $this->client->getRelation(RELATION_0_NAME)->findConnections();
        self::assertSame(
            $this->post_ids[0],
            $hydrated->resolveEntities(EndpointTarget::to())[0]->getEndpoints()['to']->getEntity()->ID
        );

        $wrong = new ConnectionCollection([
            $this->connection(7, $this->page_ids[0], $this->page_ids[0]),
        ]);
        self::assertSame(
            ResolvedEndpoint::UNAVAILABLE,
            $wrong->resolveEntities(EndpointTarget::to())[0]->getEndpoints()['to']->getStatus()
        );
    }

    public function test_cold_cache_query_growth_for_unique_and_missing_ids(): void
    {
        global $wpdb;
        $postIds = [];
        for ($i = 0; $i < 50; ++$i) {
            $postIds[] = self::factory()->post->create([ 'post_status' => 'publish' ]);
        }

        $deltas = [];
        foreach ([ 'found' => $postIds, 'missing' => range(900000000, 900000049) ] as $kind => $ids) {
            foreach ([ 1, 50 ] as $count) {
                $selected = array_slice($ids, 0, $count);
                foreach ($selected as $id) {
                    wp_cache_delete($id, 'posts');
                }
                $rows = new ConnectionCollection();
                foreach ($selected as $index => $id) {
                    $rows->add($this->connection($index + 1, $this->page_ids[0], $id));
                }

                $before = $wpdb->num_queries;
                $results = $rows->resolveEntities(EndpointTarget::to());
                $deltas[$kind][$count] = $wpdb->num_queries - $before;
                self::assertCount($count, $results);
                self::assertSame(
                    'found' === $kind ? ResolvedEndpoint::RESOLVED : ResolvedEndpoint::UNAVAILABLE,
                    $results[0]->getEndpoints()['to']->getStatus()
                );
            }
            self::assertLessThanOrEqual($deltas[$kind][1] + 1, $deltas[$kind][50]);
        }

        $log = getenv('WPC_API03_QUERY_LOG');
        if (false !== $log && '' !== $log) {
            file_put_contents($log, json_encode($deltas, JSON_PRETTY_PRINT));
        }
    }

    public function test_switched_site_does_not_resolve_previous_site_client(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Multisite only.');
        }

        $rows = new ConnectionCollection([
            $this->connection(1, $this->page_ids[0], $this->post_ids[0]),
        ]);
        $otherSite = self::factory()->blog->create();
        switch_to_blog($otherSite);
        try {
            self::assertSame(
                ResolvedEndpoint::UNAVAILABLE,
                $rows->resolveEntities(EndpointTarget::to())[0]->getEndpoints()['to']->getStatus()
            );
        } finally {
            restore_current_blog();
        }
    }

    private function registerRelation(string $name, string $from, string $to): void
    {
        $relation = new RelationQuery();
        $relation->set('name', $name)->set('from', $from)->set('to', $to);
        $this->client->registerRelation($relation);
    }

    private function connection(
        int $id,
        int $from,
        int $to,
        string $relation = RELATION_0_NAME,
        ?Client $client = null
    ): Connection
    {
        $query = new ConnectionQuery($from, $to);
        $query->set('id', $id)->set('relation', $relation);
        $connection = new Connection($query);
        $connection->setClient($client ?? $this->client);

        return $connection;
    }
}
