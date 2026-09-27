<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Client;
use iTRON\wpConnections\EntityResolution;
use iTRON\wpConnections\EntityResolverInterface;
use iTRON\wpConnections\Query\Connection;
use iTRON\wpConnections\Query\Relation;
use iTRON\wpConnections\RestEntityAdapterInterface;
use iTRON\wpConnections\RestResponse\CollectionItem;
use iTRON\wpConnections\RestResponse\ExpandedCollectionItem;
use WP_REST_Request;

class RestRelatedEntitiesTest extends WPConnectionsTestCase
{
    public function set_up()
    {
        parent::set_up();
        $this->set_up_rest_server();
        $this->authenticate_as_administrator();
    }

    public function tear_down()
    {
        try {
            $this->tear_down_rest_server();
        } finally {
            parent::tear_down();
        }
    }

    /**
     * @dataProvider non_query_argument_provider
     */
    public function test_non_query_entity_arguments_fail_before_storage_selection(
        string $parameter,
        array $query,
        array $payload,
        bool $json
    ): void {
        $request = new WP_REST_Request('GET', $this->get_rest_route('/relation/' . RELATION_0_NAME));
        $request->set_query_params($query);
        if ($json) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body(wp_json_encode($payload));
        } else {
            $request->set_body_params($payload);
        }

        $queries = [];
        $listener = static function ($sql) use (&$queries): void {
            $queries[] = $sql;
        };
        add_action('wpConnections/storage/findConnections/dbQuery', $listener, 10, 1);
        try {
            $response = $this->rest_server->dispatch($request);
        } finally {
            remove_action('wpConnections/storage/findConnections/dbQuery', $listener, 10);
        }

        self::assertSame(400, $response->get_status());
        self::assertSame('rest_invalid_param', $response->get_data()['code']);
        self::assertArrayHasKey($parameter, $response->get_data()['data']['params']);
        self::assertSame([], $queries);
    }

    public function non_query_argument_provider(): array
    {
        $base = [ 'target' => 'to', 'from' => 1 ];
        $cases = [
            'entity body only' => [ 'entity', $base, [ 'entity' => [ 'status' => 'draft' ] ] ],
            'entity conflict' => [
                'entity',
                $base + [ 'entity' => [ 'status' => 'publish' ] ],
                [ 'entity' => [ 'status' => 'draft' ] ],
            ],
            'target body only' => [ 'target', [ 'from' => 1 ], [ 'target' => 'to' ] ],
            'representation body only' => [ 'representation', $base, [ 'representation' => 'expanded' ] ],
            'context body only' => [ 'context', $base, [ 'context' => 'edit' ] ],
            'page body only' => [ 'page', $base, [ 'page' => 1 ] ],
            'per_page body only' => [ 'per_page', $base, [ 'per_page' => 1 ] ],
        ];
        foreach ($cases as $label => $case) {
            $cases[$label . ' JSON'] = [ ...$case, true ];
            $cases[$label . ' form'] = [ ...$case, false ];
            unset($cases[$label]);
        }
        return $cases;
    }

    public function test_default_and_expanded_projection_use_the_approved_shape_and_roles(): void
    {
        $first = $this->connect(RELATION_0_NAME, $this->page_ids[0], $this->post_ids[0]);
        $second = $this->connect(RELATION_0_NAME, $this->page_ids[0], $this->post_ids[1]);

        $legacy = $this->relation([ 'from' => $this->page_ids[0] ]);
        self::assertSame(200, $legacy->get_status());
        self::assertCount(2, $legacy->get_data());
        foreach ($legacy->get_data() as $item) {
            self::assertInstanceOf(CollectionItem::class, $item);
            self::assertNotInstanceOf(ExpandedCollectionItem::class, $item);
            self::assertSame(
                [ 'id', 'title', 'relation', 'from', 'to', 'order', 'meta' ],
                array_keys($item->get_data())
            );
        }

        $expanded = $this->relation([
            'from' => $this->page_ids[0],
            'representation' => 'expanded',
            'target' => 'to',
        ]);
        self::assertSame(200, $expanded->get_status());
        self::assertSame([ $first->id, $second->id ], $this->ids($expanded));
        foreach ($expanded->get_data() as $item) {
            self::assertInstanceOf(ExpandedCollectionItem::class, $item);
            self::assertSame([ 'to' ], array_keys($item->entities));
            self::assertSame('resolved', $item->entities['to']['status']);
            self::assertSame($item->get_data()['to'], $item->entities['to']['data']['id']);
            self::assertArrayHasKey('title', $item->entities['to']['data']);
        }

        $reverse = $this->relation([
            'to' => $this->post_ids[0], 'representation' => 'expanded', 'target' => 'opposite',
        ]);
        self::assertSame([ 'from' ], array_keys($reverse->get_data()[0]->entities));
        self::assertSame($this->page_ids[0], $reverse->get_data()[0]->entities['from']['data']['id']);

        $page = $this->relation([ 'from' => $this->page_ids[0], 'per_page' => 1 ]);
        self::assertSame('2', $page->get_headers()['X-WP-Total']);
        self::assertCount(1, $page->get_data());
        self::assertNotInstanceOf(ExpandedCollectionItem::class, $page->get_data()[0]);
    }

    public function test_both_and_opposite_preserve_self_and_unavailable_rows(): void
    {
        $relation = new Relation();
        $relation->set('name', 'rest-api04-self')->set('from', 'post')->set('to', 'post');
        $relation->set('cardinality', 'm-m')->set('closurable', true);
        $this->client->registerRelation($relation);
        $this->connect('rest-api04-self', $this->post_ids[0], $this->post_ids[0]);
        $incident = $this->connect('rest-api04-self', $this->post_ids[0], $this->post_ids[1]);

        $both = $this->relation([
            'both' => $this->post_ids[0], 'target' => 'both', 'representation' => 'expanded',
        ], 'rest-api04-self');
        self::assertSame(200, $both->get_status());
        self::assertSame([ 'from', 'to' ], array_keys($both->get_data()[0]->entities));
        self::assertSame(
            $both->get_data()[0]->entities['from']['data']['id'],
            $both->get_data()[0]->entities['to']['data']['id']
        );

        $opposite = $this->relation([
            'both' => $this->post_ids[0], 'target' => 'opposite', 'representation' => 'expanded',
        ], 'rest-api04-self');
        self::assertSame(200, $opposite->get_status());
        self::assertSame([], $opposite->get_data()[0]->entities);
        self::assertSame([ 'to' ], array_keys($opposite->get_data()[1]->entities));

        global $wpdb;
        $table = $wpdb->{$this->client->getStorage()->get_connections_table()};
        $wpdb->update($table, [ 'to' => 987654321 ], [ 'ID' => $incident->id ]);
        $missing = $this->relation([
            'from' => $this->post_ids[0], 'target' => 'to', 'representation' => 'expanded',
        ], 'rest-api04-self');
        self::assertSame([ 'status' => 'unavailable' ], $missing->get_data()[1]->entities['to']);
    }

    public function test_filters_and_pagination_apply_before_totals_without_holes(): void
    {
        $a = $this->post_ids[0];
        $b = $this->post_ids[1];
        $draft = self::factory()->post->create([
            'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Hidden draft',
        ]);
        $this->connect(RELATION_0_NAME, $this->page_ids[0], $draft, 0);
        $publishedOne = $this->connect(RELATION_0_NAME, $this->page_ids[0], $a, 1);
        $publishedTwo = $this->connect(RELATION_0_NAME, $this->page_ids[0], $b, 1);

        $query = [
            'from' => $this->page_ids[0], 'target' => 'to', 'representation' => 'expanded',
            'entity' => [ 'status' => [ 'publish' ], 'type' => 'post' ],
            'per_page' => 1,
        ];
        $first = $this->relation($query);
        self::assertSame(200, $first->get_status());
        self::assertSame([ $publishedOne->id ], $this->ids($first));
        self::assertSame('2', $first->get_headers()['X-WP-Total']);
        self::assertSame('2', $first->get_headers()['X-WP-TotalPages']);
        $second = $this->relation($query + [ 'page' => 2 ]);
        self::assertSame([ $publishedTwo->id ], $this->ids($second));
        $empty = $this->relation($query + [ 'page' => 3 ]);
        self::assertSame([], $empty->get_data());
        self::assertSame('2', $empty->get_headers()['X-WP-Total']);

        $or = $this->relation([
            'from' => $this->page_ids[0], 'target' => 'to',
            'entity' => [ 'status' => [ 'publish', 'draft' ], 'search' => 'Hidden' ],
        ]);
        self::assertSame([ $draft ], array_column(array_map(
            static fn(CollectionItem $item): array => $item->get_data(),
            $or->get_data()
        ), 'to'));
    }

    public function test_route_permission_and_post_context_are_independent(): void
    {
        $private = self::factory()->post->create([
            'post_type' => 'post', 'post_status' => 'private', 'post_title' => 'Protected title',
        ]);
        $this->connect(RELATION_0_NAME, $this->page_ids[0], $private);
        $subscriber = self::factory()->user->create([ 'role' => 'subscriber' ]);
        $this->client->capabilities->getRelation = 'read';
        wp_set_current_user($subscriber);

        $response = $this->relation([
            'from' => $this->page_ids[0], 'target' => 'to', 'representation' => 'expanded',
        ]);
        self::assertSame(200, $response->get_status());
        self::assertSame([ 'status' => 'unavailable' ], $response->get_data()[0]->entities['to']);
        self::assertStringNotContainsString('Protected title', wp_json_encode($response->get_data()));

        $filtered = $this->relation([
            'from' => $this->page_ids[0], 'target' => 'to',
            'entity' => [ 'status' => 'private' ], 'page' => 1,
        ]);
        self::assertSame([], $filtered->get_data());
        self::assertSame('0', $filtered->get_headers()['X-WP-Total']);

        $edit = $this->relation([
            'from' => $this->page_ids[0], 'target' => 'to',
            'representation' => 'expanded', 'context' => 'edit',
        ]);
        self::assertSame([ 'status' => 'unavailable' ], $edit->get_data()[0]->entities['to']);
        $this->authenticate_as_administrator();
        $allowed = $this->relation([
            'from' => $this->page_ids[0], 'target' => 'to',
            'representation' => 'expanded', 'context' => 'edit',
        ]);
        self::assertSame('resolved', $allowed->get_data()[0]->entities['to']['status']);
    }

    public function test_invalid_arguments_return_native_rest_errors(): void
    {
        $invalid = [
            [ 'target' => 'wrong' ],
            [ 'target' => 'opposite' ],
            [ 'from' => $this->page_ids[0], 'to' => $this->post_ids[0], 'target' => 'opposite' ],
            [ 'representation' => 'full' ],
            [ 'context' => 'raw' ],
            [ 'page' => 0 ],
            [ 'per_page' => 101 ],
            [ 'target' => 'to', 'entity' => [ 'secret' => 'yes' ] ],
            [ 'target' => 'to', 'entity' => [ 'status' => [] ] ],
            [ 'target' => 'to', 'entity' => [ 'status' => [ 'publish', [] ] ] ],
            [ 'entity' => [ 'status' => 'publish' ] ],
            [ 'unexpected' => 'yes' ],
        ];
        foreach ($invalid as $query) {
            $response = $this->relation($query);
            self::assertSame(400, $response->get_status(), wp_json_encode($query));
            self::assertSame('rest_invalid_param', $response->get_data()['code'], wp_json_encode($query));
        }

        self::assertSame(200, $this->relation([ '_locale' => 'user' ])->get_status());
    }

    public function test_search_does_not_match_password_protected_content(): void
    {
        $protected = self::factory()->post->create([
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_title' => 'Public heading',
            'post_content' => 'concealed search needle',
            'post_password' => 'password',
        ]);
        $this->connect(RELATION_0_NAME, $this->page_ids[0], $protected);

        $filtered = $this->relation([
            'from' => $this->page_ids[0], 'target' => 'to',
            'entity' => [ 'search' => 'concealed search needle' ], 'page' => 1,
        ]);
        self::assertSame(200, $filtered->get_status());
        self::assertSame([], $filtered->get_data());
        self::assertSame('0', $filtered->get_headers()['X-WP-Total']);

        $embed = $this->relation([
            'from' => $this->page_ids[0], 'target' => 'to',
            'representation' => 'expanded', 'context' => 'embed',
        ]);
        self::assertSame(200, $embed->get_status());
        self::assertSame('resolved', $embed->get_data()[0]->entities['to']['status']);
        self::assertStringNotContainsString('concealed search needle', wp_json_encode($embed->get_data()));
    }

    public function test_both_filter_matches_one_permitted_role_and_hides_the_other(): void
    {
        $private = self::factory()->post->create([
            'post_type' => 'post', 'post_status' => 'private', 'post_title' => 'Private endpoint',
        ]);
        $relation = new Relation();
        $relation->set('name', 'rest-api04-both')->set('from', 'post')->set('to', 'post');
        $relation->set('cardinality', 'm-m');
        $this->client->registerRelation($relation);
        $this->connect('rest-api04-both', $private, $this->post_ids[0]);
        $subscriber = self::factory()->user->create([ 'role' => 'subscriber' ]);
        $this->client->capabilities->getRelation = 'read';
        wp_set_current_user($subscriber);

        $response = $this->relation([
            'target' => 'both', 'representation' => 'expanded',
            'entity' => [ 'status' => 'publish' ], 'per_page' => 1,
        ], 'rest-api04-both');
        self::assertSame(200, $response->get_status());
        self::assertSame('1', $response->get_headers()['X-WP-Total']);
        self::assertSame([ 'status' => 'unavailable' ], $response->get_data()[0]->entities['from']);
        self::assertSame('resolved', $response->get_data()[0]->entities['to']['status']);
        self::assertStringNotContainsString('Private endpoint', wp_json_encode($response->get_data()));
    }

    public function test_registered_custom_post_type_uses_its_rest_controller(): void
    {
        register_post_type('api04_cpt', [
            'public' => true,
            'show_in_rest' => true,
            'supports' => [ 'title', 'editor' ],
        ]);
        try {
            $relation = new Relation();
            $relation->set('name', 'rest-api04-cpt')->set('from', 'page')->set('to', 'api04_cpt');
            $relation->set('cardinality', 'm-m');
            $this->client->registerRelation($relation);
            $post = self::factory()->post->create([
                'post_type' => 'api04_cpt', 'post_status' => 'publish', 'post_title' => 'Custom REST post',
            ]);
            $this->connect('rest-api04-cpt', $this->page_ids[0], $post);
            $response = $this->relation([
                'from' => $this->page_ids[0], 'target' => 'to', 'representation' => 'expanded',
            ], 'rest-api04-cpt');
            self::assertSame(200, $response->get_status());
            self::assertSame('resolved', $response->get_data()[0]->entities['to']['status']);
            self::assertSame($post, $response->get_data()[0]->entities['to']['data']['id']);
            self::assertSame('api04_cpt', $response->get_data()[0]->entities['to']['data']['type']);
        } finally {
            unregister_post_type('api04_cpt');
        }
    }

    public function test_non_post_adapter_batches_authorization_and_rest_preparation(): void
    {
        $resolver = new class () implements EntityResolverInterface, RestEntityAdapterInterface {
            public int $batchCalls = 0;
            public int $eligibilityCalls = 0;

            public function getSupportedEntityTypes(): array
            {
                return [ 'api04_record' ];
            }
            public function resolve(int $entityId, string $entityType): EntityResolution
            {
                return EntityResolution::accepted();
            }
            public function resolveMany(array $entityIds, string $entityType): array
            {
                ++$this->batchCalls;
                return array_fill_keys($entityIds, (object) [ 'safe' => 'record', 'secret' => 'hidden' ]);
            }
            public function getSupportedRestFilters(): array
            {
                return [ 'status' ];
            }
            public function getRestEligibleIds(array $entities, array $filters, string $context): array
            {
                ++$this->eligibilityCalls;
                return 'edit' === $context ? [] : array_keys($entities);
            }
            public function prepareEntityForRest(object $entity, string $context): array
            {
                return [ 'name' => $entity->safe ];
            }
        };
        $this->client->registerEntityResolver($resolver);
        $relation = new Relation();
        $relation->set('name', 'rest-api04-custom')->set('from', 'page')->set('to', 'api04_record');
        $relation->set('cardinality', 'm-m');
        $this->client->registerRelation($relation);
        $this->connect('rest-api04-custom', $this->page_ids[0], 7);
        $this->connect('rest-api04-custom', $this->page_ids[0], 8);

        $result = $this->relation([
            'from' => $this->page_ids[0], 'target' => 'to', 'representation' => 'expanded',
            'entity' => [ 'status' => 'active' ],
        ], 'rest-api04-custom');
        self::assertSame(200, $result->get_status());
        self::assertSame(1, $resolver->batchCalls);
        self::assertSame(1, $resolver->eligibilityCalls);
        self::assertSame([ 'name' => 'record' ], $result->get_data()[0]->entities['to']['data']);
        self::assertStringNotContainsString('hidden', wp_json_encode($result->get_data()));

        $unsupported = $this->relation([
            'target' => 'to', 'entity' => [ 'slug' => 'record' ],
        ], 'rest-api04-custom');
        self::assertSame(400, $unsupported->get_status());
        self::assertSame('rest_invalid_param', $unsupported->get_data()['code']);
    }

    public function test_legacy_duplicate_rows_keep_occurrences_and_stable_tie_order(): void
    {
        global $wpdb;

        $first = $this->connect(RELATION_0_NAME, $this->page_ids[0], $this->post_ids[0], 2);
        $table = $wpdb->{$this->client->getStorage()->get_connections_table()};
        $wpdb->insert($table, [
            'relation' => RELATION_0_NAME,
            'from' => $this->page_ids[0],
            'to' => $this->post_ids[0],
            'order' => 2,
            'title' => '',
        ]);
        $duplicateId = (int) $wpdb->insert_id;

        $response = $this->relation([
            'from' => $this->page_ids[0], 'target' => 'to',
            'representation' => 'expanded', 'per_page' => 2,
        ]);
        self::assertSame([ $first->id, $duplicateId ], $this->ids($response));
        self::assertSame('2', $response->get_headers()['X-WP-Total']);
        self::assertSame(
            $response->get_data()[0]->entities['to']['data']['id'],
            $response->get_data()[1]->entities['to']['data']['id']
        );
    }

    public function test_query_and_resolver_work_does_not_grow_per_connection(): void
    {
        global $wpdb;

        $resolver = new class () implements EntityResolverInterface, RestEntityAdapterInterface {
            public int $batchCalls = 0;
            public int $eligibilityCalls = 0;
            public function getSupportedEntityTypes(): array
            {
                return [ 'api04_budget' ];
            }
            public function resolve(int $entityId, string $entityType): EntityResolution
            {
                return EntityResolution::accepted();
            }
            public function resolveMany(array $entityIds, string $entityType): array
            {
                ++$this->batchCalls;
                $objects = [];
                foreach ($entityIds as $id) {
                    $objects[$id] = (object) [ 'id' => $id ];
                }
                return $objects;
            }
            public function getSupportedRestFilters(): array
            {
                return [ 'status' ];
            }
            public function getRestEligibleIds(array $entities, array $filters, string $context): array
            {
                ++$this->eligibilityCalls;
                return array_keys($entities);
            }
            public function prepareEntityForRest(object $entity, string $context): array
            {
                return [ 'id' => $entity->id ];
            }
        };
        $this->client->registerEntityResolver($resolver);
        $relation = new Relation();
        $relation->set('name', 'rest-api04-budget')->set('from', 'page')->set('to', 'api04_budget');
        $relation->set('cardinality', 'm-m');
        $this->client->registerRelation($relation);
        $this->connect('rest-api04-budget', $this->page_ids[0], 1001);
        $query = [
            'from' => $this->page_ids[0], 'target' => 'to', 'representation' => 'expanded',
            'entity' => [ 'status' => 'active' ], 'per_page' => 50,
        ];

        $start = $wpdb->num_queries;
        $one = $this->relation($query, 'rest-api04-budget');
        $oneQueries = $wpdb->num_queries - $start;
        for ($id = 1002; $id <= 1050; ++$id) {
            $this->connect('rest-api04-budget', $this->page_ids[0], $id);
        }
        $start = $wpdb->num_queries;
        $fifty = $this->relation($query, 'rest-api04-budget');
        $fiftyQueries = $wpdb->num_queries - $start;

        self::assertSame(200, $one->get_status());
        self::assertSame(200, $fifty->get_status());
        self::assertCount(50, $fifty->get_data());
        self::assertSame(2, $resolver->batchCalls);
        self::assertSame(2, $resolver->eligibilityCalls);
        self::assertLessThanOrEqual(
            $oneQueries + 5,
            $fiftyQueries,
            "Full query deltas: one={$oneQueries}, fifty={$fiftyQueries}"
        );
    }

    public function test_post_expansion_uses_batched_queries_for_fifty_connections(): void
    {
        $this->connect(RELATION_0_NAME, $this->page_ids[0], $this->post_ids[0]);
        $query = [
            'from' => $this->page_ids[0], 'target' => 'to', 'representation' => 'expanded',
        ];
        wp_cache_flush();
        [ $one, $oneQueries, $oneSql ] = $this->measureRelationSql($query);
        for ($index = 1; $index < 50; ++$index) {
            $post = self::factory()->post->create([
                'post_type' => 'post', 'post_status' => 'publish',
                'post_title' => 'REST API04 performance ' . $index,
            ]);
            $this->connect(RELATION_0_NAME, $this->page_ids[0], $post);
        }
        wp_cache_flush();
        [ $fifty, $fiftyQueries, $fiftySql ] = $this->measureRelationSql($query);
        $coreRevisions = static function (array $statements): int {
            return count(array_filter($statements, static function (string $sql): bool {
                return false !== strpos($sql, "post_type = 'revision'");
            }));
        };
        $oneRevisionQueries = $coreRevisions($oneSql);
        $fiftyRevisionQueries = $coreRevisions($fiftySql);

        self::assertSame(200, $one->get_status());
        self::assertSame(200, $fifty->get_status());
        self::assertCount(50, $fifty->get_data());
        self::assertLessThanOrEqual(
            $oneQueries - $oneRevisionQueries + 10,
            $fiftyQueries - $fiftyRevisionQueries,
            "Full SQL deltas: one={$oneQueries}, fifty={$fiftyQueries}; " .
                "WordPress revision lookups: one={$oneRevisionQueries}, fifty={$fiftyRevisionQueries}"
        );
    }

    private function measureRelationSql(array $query): array
    {
        global $wpdb;

        $statements = [];
        $capture = static function (string $sql) use (&$statements): string {
            $statements[] = $sql;
            return $sql;
        };
        add_filter('query', $capture);
        $start = $wpdb->num_queries;
        try {
            $response = $this->relation($query);
        } finally {
            remove_filter('query', $capture);
        }
        return [ $response, $wpdb->num_queries - $start, $statements ];
    }

    private function relation(array $query, string $relation = RELATION_0_NAME): \WP_REST_Response
    {
        $request = new WP_REST_Request('GET', $this->get_rest_route('/relation/' . $relation));
        $request->set_query_params($query);
        return $this->rest_server->dispatch($request);
    }

    private function connect(string $relation, int $from, int $to, int $order = 0): \iTRON\wpConnections\Connection
    {
        $query = new Connection($from, $to);
        $query->set('order', $order);
        return $this->client->getRelation($relation)->createConnection($query);
    }

    private function ids(\WP_REST_Response $response): array
    {
        return array_map(static fn($item): int => $item->get_data()['id'], $response->get_data());
    }
}
