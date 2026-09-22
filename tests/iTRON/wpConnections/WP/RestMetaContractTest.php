<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Client;
use iTRON\wpConnections\Helpers\Database;
use iTRON\wpConnections\Meta;
use iTRON\wpConnections\Query\Connection;
use iTRON\wpConnections\Query\Relation;

class RestMetaContractTest extends WPConnectionsTestCase
{
    private ?Client $secondary_client = null;

    public function set_up()
    {
        parent::set_up();
        $this->set_up_rest_server();
        $this->authenticate_as_administrator();
    }

    public function tear_down()
    {
        try {
            $this->tear_down_secondary_client();
            $this->tear_down_rest_server();
        } finally {
            parent::tear_down();
        }
    }

    public function test_delete_route_declares_compatible_metadata_argument(): void
    {
        $route = $this->get_rest_route(
            '/relation/(?P<relation>[\w-]+)/(?P<connectionID>[\d]+)/meta'
        );
        $handlers = $this->rest_server->get_routes('wp-connections/v1')[ $route ];
        $delete_handler = null;

        foreach ($handlers as $handler) {
            if (is_array($handler) && ! empty($handler['methods']['DELETE'])) {
                $delete_handler = $handler;
                break;
            }
        }

        self::assertIsArray($delete_handler);
        self::assertArrayHasKey('meta', $delete_handler['args']);
        self::assertFalse($delete_handler['args']['meta']['required']);
        self::assertSame([], $delete_handler['args']['meta']['default']);
        self::assertStringContainsString('key/value rows', $delete_handler['args']['meta']['description']);
        self::assertStringContainsString('associative key/value map', $delete_handler['args']['meta']['description']);
    }

    public function test_post_appends_duplicates_and_allowed_falsy_values(): void
    {
        $connection = $this->create_connection_with_meta(
            RELATION_0_NAME,
            [ [ 'key' => 'existing', 'value' => 'preserved' ] ]
        );
        $response = $this->dispatch_meta(
            'POST',
            RELATION_0_NAME,
            $connection->id,
            [
                'meta' => [
                    [ 'key' => 'duplicate', 'value' => 'first' ],
                    [ 'key' => 'duplicate', 'value' => 'second' ],
                    [ 'key' => 'integer-zero', 'value' => 0 ],
                    [ 'key' => 'string-zero', 'value' => '0' ],
                    [ 'key' => 'false', 'value' => false ],
                    [ 'key' => 'empty-string', 'value' => '' ],
                ],
            ]
        );

        $this->assert_update_success_wire($connection->id, RELATION_0_NAME, $response);
        self::assertSame(
            [
                'existing'     => [ 'preserved' ],
                'duplicate'    => [ 'first', 'second' ],
                'integer-zero' => [ '0' ],
                'string-zero'  => [ '0' ],
                'false'        => [ '' ],
                'empty-string' => [ '' ],
            ],
            $this->persisted_meta(RELATION_0_NAME, $connection->id)
        );
    }

    /**
     * @dataProvider no_op_update_provider
     */
    public function test_post_and_patch_omitted_or_empty_are_no_ops(string $method, array $payload): void
    {
        $initial = [ 'existing' => [ 'first', 'second' ] ];
        $connection = $this->create_connection_with_meta(
            RELATION_0_NAME,
            [
                [ 'key' => 'existing', 'value' => 'first' ],
                [ 'key' => 'existing', 'value' => 'second' ],
            ]
        );

        $response = $this->dispatch_meta(
            $method,
            RELATION_0_NAME,
            $connection->id,
            $payload
        );

        $this->assert_update_success_wire($connection->id, RELATION_0_NAME, $response);
        self::assertSame($initial, $this->persisted_meta(RELATION_0_NAME, $connection->id));
    }

    public function test_patch_replaces_supplied_keys_and_preserves_unrelated_rows(): void
    {
        $connection = $this->create_connection_with_meta(
            RELATION_0_NAME,
            [
                [ 'key' => 'replace', 'value' => 'old-one' ],
                [ 'key' => 'replace', 'value' => 'old-two' ],
                [ 'key' => 'keep', 'value' => 'untouched' ],
            ]
        );

        $response = $this->dispatch_meta(
            'PATCH',
            RELATION_0_NAME,
            $connection->id,
            [
                'meta' => [
                    [ 'key' => 'replace', 'value' => 'new-one' ],
                    [ 'key' => 'replace', 'value' => 'new-two' ],
                ],
            ]
        );

        $this->assert_update_success_wire($connection->id, RELATION_0_NAME, $response);
        self::assertSame(
            [
                'keep'    => [ 'untouched' ],
                'replace' => [ 'new-one', 'new-two' ],
            ],
            $this->persisted_meta(RELATION_0_NAME, $connection->id)
        );
    }

    public function test_put_replaces_all_metadata(): void
    {
        $connection = $this->create_connection_with_meta(
            RELATION_0_NAME,
            [ [ 'key' => 'old', 'value' => 'removed' ] ]
        );

        $response = $this->dispatch_meta(
            'PUT',
            RELATION_0_NAME,
            $connection->id,
            [
                'meta' => [
                    [ 'key' => 'replacement', 'value' => 'first' ],
                    [ 'key' => 'replacement', 'value' => 'second' ],
                ],
            ]
        );

        $this->assert_update_success_wire($connection->id, RELATION_0_NAME, $response);
        self::assertSame(
            [ 'replacement' => [ 'first', 'second' ] ],
            $this->persisted_meta(RELATION_0_NAME, $connection->id)
        );
    }

    /**
     * @dataProvider clear_all_update_provider
     */
    public function test_put_omitted_or_empty_clears_all_metadata(array $payload): void
    {
        $connection = $this->create_connection_with_meta(
            RELATION_0_NAME,
            [ [ 'key' => 'old', 'value' => 'removed' ] ]
        );

        $response = $this->dispatch_meta('PUT', RELATION_0_NAME, $connection->id, $payload);

        $this->assert_update_success_wire($connection->id, RELATION_0_NAME, $response);
        self::assertSame([], $this->persisted_meta(RELATION_0_NAME, $connection->id));
    }

    /**
     * @dataProvider invalid_persisted_meta_provider
     */
    public function test_rejects_invalid_persisted_metadata_before_mutation(
        string $method,
        array $row,
        string $message
    ): void {
        $connection = $this->create_connection_with_meta(
            RELATION_0_NAME,
            [ [ 'key' => 'existing', 'value' => 'preserved' ] ]
        );
        $success_hooks = 0;
        $count_success = static function () use (&$success_hooks): void {
            $success_hooks++;
        };
        add_action('wpConnections/storage/removeConnectionMeta/after', $count_success);
        add_action('wpConnections/storage/addConnectionMeta/after', $count_success);

        try {
            $response = $this->dispatch_meta(
                $method,
                RELATION_0_NAME,
                $connection->id,
                [ 'meta' => [ $row ] ]
            );
        } finally {
            remove_action('wpConnections/storage/addConnectionMeta/after', $count_success);
            remove_action('wpConnections/storage/removeConnectionMeta/after', $count_success);
        }

        $this->assert_domain_error(300, 400, $message, $response);
        self::assertSame(0, $success_hooks);
        self::assertSame(
            [ 'existing' => [ 'preserved' ] ],
            $this->persisted_meta(RELATION_0_NAME, $connection->id)
        );
    }

    public function test_delete_supports_row_selectors_and_null_value_wildcard(): void
    {
        $connection = $this->create_connection_with_meta(
            RELATION_0_NAME,
            [
                [ 'key' => 'selected', 'value' => 'first' ],
                [ 'key' => 'selected', 'value' => 'second' ],
                [ 'key' => 'wildcard', 'value' => 'one' ],
                [ 'key' => 'wildcard', 'value' => 'two' ],
                [ 'key' => 'keep', 'value' => 'untouched' ],
            ]
        );

        $response = $this->dispatch_meta(
            'DELETE',
            RELATION_0_NAME,
            $connection->id,
            [ 'meta' => [ [ 'key' => 'selected', 'value' => 'first' ] ] ]
        );
        self::assertSame(200, $response->get_status());
        self::assertSame([ 'deleted' => 1 ], $response->get_data());

        $response = $this->dispatch_meta(
            'DELETE',
            RELATION_0_NAME,
            $connection->id,
            [ 'meta' => [ [ 'key' => 'wildcard', 'value' => null ] ] ]
        );
        self::assertSame(200, $response->get_status());
        self::assertSame([ 'deleted' => 2 ], $response->get_data());
        self::assertSame(
            [
                'selected' => [ 'second' ],
                'keep'     => [ 'untouched' ],
            ],
            $this->persisted_meta(RELATION_0_NAME, $connection->id)
        );
    }

    public function test_delete_supports_associative_map_selectors(): void
    {
        $connection = $this->create_connection_with_meta(
            RELATION_0_NAME,
            [
                [ 'key' => 'selected', 'value' => 'first' ],
                [ 'key' => 'selected', 'value' => 'second' ],
                [ 'key' => 'keep', 'value' => 'untouched' ],
            ]
        );

        $response = $this->dispatch_meta(
            'DELETE',
            RELATION_0_NAME,
            $connection->id,
            [ 'meta' => [ 'selected' => [ 'first', 'second' ] ] ]
        );

        self::assertSame(200, $response->get_status());
        self::assertSame([ 'deleted' => 2 ], $response->get_data());
        self::assertSame(
            [ 'keep' => [ 'untouched' ] ],
            $this->persisted_meta(RELATION_0_NAME, $connection->id)
        );
    }

    public function test_delete_existing_connection_with_no_matching_selector_is_successful_zero(): void
    {
        $connection = $this->create_connection_with_meta(
            RELATION_0_NAME,
            [ [ 'key' => 'keep', 'value' => 'untouched' ] ]
        );

        $response = $this->dispatch_meta(
            'DELETE',
            RELATION_0_NAME,
            $connection->id,
            [ 'meta' => [ [ 'key' => 'missing', 'value' => 'value' ] ] ]
        );

        self::assertSame(200, $response->get_status());
        self::assertSame([ 'deleted' => 0 ], $response->get_data());
        self::assertSame(
            [ 'keep' => [ 'untouched' ] ],
            $this->persisted_meta(RELATION_0_NAME, $connection->id)
        );
    }

    /**
     * @dataProvider delete_all_provider
     */
    public function test_delete_absent_empty_or_top_level_null_deletes_all(array $payload): void
    {
        $connection = $this->create_connection_with_meta(
            RELATION_0_NAME,
            [
                [ 'key' => 'first', 'value' => 'one' ],
                [ 'key' => 'second', 'value' => 'two' ],
            ]
        );

        $response = $this->dispatch_meta(
            'DELETE',
            RELATION_0_NAME,
            $connection->id,
            $payload
        );

        self::assertSame(200, $response->get_status());
        self::assertSame([ 'deleted' => 2 ], $response->get_data());
        self::assertSame([], $this->persisted_meta(RELATION_0_NAME, $connection->id));
    }

    /**
     * @dataProvider path_selector_conflict_provider
     */
    public function test_path_relation_and_connection_are_authoritative(
        string $method,
        string $selector_source
    ): void {
        $target = $this->create_connection_with_meta(
            RELATION_0_NAME,
            [ [ 'key' => 'target', 'value' => 'original' ] ],
            0
        );
        $other = $this->create_connection_with_meta(
            RELATION_1_NAME,
            [ [ 'key' => 'other', 'value' => 'preserved' ] ],
            1
        );
        $secondary = $this->create_secondary_client_connection();
        self::assertNotSame($target->id, $other->id);
        self::assertSame($target->id, $secondary->id);
        $payload = [
            'meta' => 'DELETE' === $method
                ? [ [ 'key' => 'target', 'value' => 'original' ] ]
                : [ [ 'key' => 'path-selected', 'value' => 'yes' ] ],
        ];
        $conflicting_selectors = [
            'client'       => $this->secondary_client->getName(),
            'relation'     => RELATION_1_NAME,
            'connectionID' => $other->id,
        ];
        $query_params = [];
        if ('body' === $selector_source) {
            $payload = array_merge($payload, $conflicting_selectors);
        } else {
            $query_params = $conflicting_selectors;
        }

        $response = $this->dispatch_meta(
            $method,
            RELATION_0_NAME,
            $target->id,
            $payload,
            $query_params
        );

        self::assertSame(200, $response->get_status());
        self::assertSame(
            [ 'other' => [ 'preserved' ] ],
            $this->persisted_meta(RELATION_1_NAME, $other->id)
        );
        self::assertSame(
            [ 'secondary' => [ 'preserved' ] ],
            $this->persisted_meta_for_client(
                $this->secondary_client,
                RELATION_0_NAME,
                $secondary->id
            )
        );
        if ('DELETE' === $method) {
            self::assertSame([ 'deleted' => 1 ], $response->get_data());
            self::assertSame([], $this->persisted_meta(RELATION_0_NAME, $target->id));
        } else {
            $this->assert_update_success_wire($target->id, RELATION_0_NAME, $response);
            $expected_target_meta = 'PUT' === $method
                ? [ 'path-selected' => [ 'yes' ] ]
                : [
                    'target'        => [ 'original' ],
                    'path-selected' => [ 'yes' ],
                ];
            self::assertSame(
                $expected_target_meta,
                $this->persisted_meta(RELATION_0_NAME, $target->id)
            );
        }
    }

    /**
     * @dataProvider meta_method_provider
     */
    public function test_missing_or_foreign_connection_is_domain_not_found(string $method): void
    {
        $foreign = $this->create_connection_with_meta(
            RELATION_0_NAME,
            [ [ 'key' => 'foreign', 'value' => 'preserved' ] ]
        );
        $payload = [ 'meta' => [ [ 'key' => 'new', 'value' => 'value' ] ] ];

        $missing = $this->dispatch_meta($method, RELATION_0_NAME, 999999, $payload);
        $this->assert_domain_error(2, 404, 'Connection not found.', $missing);

        $foreign_response = $this->dispatch_meta(
            $method,
            RELATION_1_NAME,
            $foreign->id,
            $payload
        );
        $this->assert_domain_error(2, 404, 'Connection not found.', $foreign_response);
        self::assertSame(
            [ 'foreign' => [ 'preserved' ] ],
            $this->persisted_meta(RELATION_0_NAME, $foreign->id)
        );
    }

    public function test_malformed_custom_hydration_maps_code_304_to_bad_request_without_mutation(): void
    {
        $client_name = 'rest05-malformed-hydration';
        $malformed_client = null;
        $storage_filter = static function (string $storage_class, Client $client) use ($client_name): string {
            return $client_name === $client->getName()
                ? RestMetaMalformedHydrationStorage::class
                : $storage_class;
        };
        RestMetaMalformedHydrationStorage::reset();
        add_filter('wpConnections/factory/getStorage/class', $storage_filter, 10, 2);

        try {
            $malformed_client = new Client($client_name);
        } finally {
            remove_filter('wpConnections/factory/getStorage/class', $storage_filter, 10);
        }

        $relation = new Relation();
        $relation->set('name', RELATION_0_NAME);
        $relation->set('from', 'page');
        $relation->set('to', 'post');
        $relation->set('cardinality', 'm-m');
        $malformed_client->registerRelation($relation);

        $success_hooks = 0;
        $count_success = static function () use (&$success_hooks): void {
            $success_hooks++;
        };
        add_action('wpConnections/storage/removeConnectionMeta/after', $count_success);
        add_action('wpConnections/storage/addConnectionMeta/after', $count_success);

        try {
            $response = $this->dispatch_rest_request(
                'PATCH',
                '/wp-connections/v1/client/' . $client_name .
                    '/relation/' . RELATION_0_NAME . '/4242/meta',
                [ 'meta' => [ [ 'key' => 'replacement', 'value' => 'rejected' ] ] ]
            );
        } finally {
            remove_action('wpConnections/storage/addConnectionMeta/after', $count_success);
            remove_action('wpConnections/storage/removeConnectionMeta/after', $count_success);
            $malformed_client->dispose();
        }

        $this->assert_domain_error(
            304,
            400,
            'Cannot update uninitialized connection',
            $response
        );
        self::assertSame(1, RestMetaMalformedHydrationStorage::$find_calls);
        self::assertSame(0, RestMetaMalformedHydrationStorage::$mutation_calls);
        self::assertSame(0, $success_hooks);
    }

    /**
     * @dataProvider storage_failure_provider
     */
    public function test_storage_failure_is_generic_atomic_and_emits_no_success_hook(
        string $method,
        string $operation
    ): void {
        global $wpdb;

        $connection = $this->create_connection_with_meta(
            RELATION_0_NAME,
            [ [ 'key' => 'existing', 'value' => 'preserved' ] ]
        );
        $meta_table = $wpdb->prefix . $this->client->getStorage()->get_meta_table();
        $connections_table = $wpdb->prefix . $this->client->getStorage()->get_connections_table();
        if ('add' === $operation) {
            $query_fragment = "INSERT INTO `{$meta_table}`";
        } elseif ('delete' === $operation) {
            $query_fragment = "DELETE FROM {$meta_table}";
        } else {
            $query_fragment = "SELECT c.*, m.* FROM {$connections_table}";
        }
        $interceptions = 0;
        $query_filter = static function (string $query) use ($query_fragment, &$interceptions): string {
            if (0 === $interceptions && false !== strpos($query, $query_fragment)) {
                $interceptions++;
                return 'SELECT * FROM `wpconnections_rest05_secret_failure`';
            }

            return $query;
        };
        $success_hooks = 0;
        $count_success = static function () use (&$success_hooks): void {
            $success_hooks++;
        };
        $suppress = $wpdb->suppress_errors();
        add_filter('query', $query_filter);
        add_action('wpConnections/storage/removeConnectionMeta/after', $count_success);
        add_action('wpConnections/storage/addConnectionMeta/after', $count_success);

        try {
            $response = $this->dispatch_meta(
                $method,
                RELATION_0_NAME,
                $connection->id,
                [ 'meta' => [ [ 'key' => 'replacement', 'value' => 'rejected' ] ] ]
            );
        } finally {
            remove_action('wpConnections/storage/addConnectionMeta/after', $count_success);
            remove_action('wpConnections/storage/removeConnectionMeta/after', $count_success);
            remove_filter('query', $query_filter);
            $wpdb->suppress_errors($suppress);
        }

        self::assertSame(1, $interceptions);
        $this->assert_internal_error($response);
        self::assertStringNotContainsString(
            'wpconnections_rest05_secret_failure',
            wp_json_encode($response->get_data())
        );
        self::assertSame(0, $success_hooks);
        self::assertSame(
            [ 'existing' => [ 'preserved' ] ],
            $this->persisted_meta(RELATION_0_NAME, $connection->id)
        );
    }

    public function no_op_update_provider(): array
    {
        return [
            'POST omitted' => [ 'POST', [] ],
            'POST empty'   => [ 'POST', [ 'meta' => [] ] ],
            'PATCH omitted' => [ 'PATCH', [] ],
            'PATCH empty'   => [ 'PATCH', [ 'meta' => [] ] ],
        ];
    }

    public function clear_all_update_provider(): array
    {
        return [
            'omitted' => [ [] ],
            'empty'   => [ [ 'meta' => [] ] ],
        ];
    }

    public function invalid_persisted_meta_provider(): array
    {
        return [
            'POST empty key' => [
                'POST',
                [ 'key' => '', 'value' => 'rejected' ],
                'Meta key cannot be empty.',
            ],
            'PATCH null value' => [
                'PATCH',
                [ 'key' => 'invalid-null', 'value' => null ],
                'Persisted meta value cannot be null.',
            ],
            'PUT empty key' => [
                'PUT',
                [ 'key' => '', 'value' => 'rejected' ],
                'Meta key cannot be empty.',
            ],
        ];
    }

    public function delete_all_provider(): array
    {
        return [
            'absent'         => [ [] ],
            'empty'          => [ [ 'meta' => [] ] ],
            'top-level null' => [ [ 'meta' => null ] ],
        ];
    }

    public function meta_method_provider(): array
    {
        return [
            'POST'   => [ 'POST' ],
            'PATCH'  => [ 'PATCH' ],
            'PUT'    => [ 'PUT' ],
            'DELETE' => [ 'DELETE' ],
        ];
    }

    public function path_selector_conflict_provider(): array
    {
        return [
            'POST body'    => [ 'POST', 'body' ],
            'POST query'   => [ 'POST', 'query' ],
            'PATCH body'   => [ 'PATCH', 'body' ],
            'PATCH query'  => [ 'PATCH', 'query' ],
            'PUT body'     => [ 'PUT', 'body' ],
            'PUT query'    => [ 'PUT', 'query' ],
            'DELETE body'  => [ 'DELETE', 'body' ],
            'DELETE query' => [ 'DELETE', 'query' ],
        ];
    }

    public function storage_failure_provider(): array
    {
        return [
            'add-meta failure'               => [ 'POST', 'add' ],
            'update lookup failure'          => [ 'PATCH', 'lookup' ],
            'delete existence lookup failure' => [ 'DELETE', 'lookup' ],
            'meta-delete failure'            => [ 'DELETE', 'delete' ],
        ];
    }

    private function dispatch_meta(
        string $method,
        string $relation,
        int $connection_id,
        array $payload = [],
        array $query_params = []
    ): \WP_REST_Response {
        $request = new \WP_REST_Request(
            $method,
            $this->get_rest_route('/relation/' . $relation . '/' . $connection_id . '/meta')
        );
        $request->set_body_params($payload);
        $request->set_query_params($query_params);

        return $this->rest_server->dispatch($request);
    }

    private function create_connection_with_meta(
        string $relation,
        array $meta,
        int $post_index = 0
    ): \iTRON\wpConnections\Connection {
        $query = new Connection($this->page_ids[0], $this->post_ids[ $post_index ]);
        $query->meta->fromArray($meta);

        return $this->client->getRelation($relation)->createConnection($query);
    }

    private function persisted_meta(string $relation, int $connection_id): array
    {
        return $this->persisted_meta_for_client($this->client, $relation, $connection_id);
    }

    private function persisted_meta_for_client(
        Client $client,
        string $relation,
        int $connection_id
    ): array {
        $query = new Connection();
        $query->set('id', $connection_id);

        return $client->getRelation($relation)->findConnections($query)->first()->meta->toArray();
    }

    private function create_secondary_client_connection(): \iTRON\wpConnections\Connection
    {
        $this->secondary_client = new Client('rest05-secondary-client');
        $relation = new Relation();
        $relation->set('name', RELATION_0_NAME);
        $relation->set('from', 'page');
        $relation->set('to', 'post');
        $relation->set('cardinality', 'm-m');
        $this->secondary_client->registerRelation($relation);

        $query = new Connection($this->page_ids[0], $this->post_ids[0]);
        $query->meta->fromArray([ [ 'key' => 'secondary', 'value' => 'preserved' ] ]);

        return $this->secondary_client->getRelation(RELATION_0_NAME)->createConnection($query);
    }

    private function tear_down_secondary_client(): void
    {
        if (null === $this->secondary_client) {
            return;
        }

        global $wpdb;

        $client_name = $this->secondary_client->getName();
        $storage = $this->secondary_client->getStorage();
        $table_keys = [ $storage->get_meta_table(), $storage->get_connections_table() ];
        $this->secondary_client->dispose();

        foreach ($table_keys as $table_key) {
            $wpdb->query("DROP TEMPORARY TABLE IF EXISTS `{$wpdb->prefix}{$table_key}`");
            $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}{$table_key}`");
            unset($wpdb->{$table_key});
        }

        $postfix = Database::normalize_table_name($client_name);
        $ownership_option = 'wpconnections_storage_owner_' . hash('sha256', $postfix);
        delete_option($ownership_option);
        wp_cache_delete($ownership_option, 'options');
        wp_cache_delete('notoptions', 'options');
        $wpdb->tables = array_values(
            array_filter(
                $wpdb->tables,
                static fn (string $table_key): bool => ! in_array($table_key, $table_keys, true)
            )
        );
        $this->secondary_client = null;
    }

    private function assert_update_success_wire(
        int $connection_id,
        string $relation,
        \WP_REST_Response $response
    ): void {
        self::assertSame(200, $response->get_status());
        $wire = json_decode(
            wp_json_encode($this->rest_server->response_to_data($response, false)),
            true
        );
        self::assertSame(
            [
                'updated' => [
                    'id'       => $connection_id,
                    'title'    => null,
                    'relation' => $relation,
                    'from'     => $this->page_ids[0],
                    'to'       => $this->post_ids[0],
                    'order'    => 0,
                    'meta'     => [ 'collectionType' => Meta::class ],
                ],
            ],
            $wire
        );
    }

    private function assert_domain_error(
        int $code,
        int $status,
        string $message,
        \WP_REST_Response $response
    ): void {
        self::assertSame($status, $response->get_status());
        self::assertSame(
            [
                'code'    => $code,
                'message' => $message,
                'data'    => [
                    'status'      => $status,
                    'domain_code' => $code,
                ],
            ],
            $response->get_data()
        );
    }

    private function assert_internal_error(\WP_REST_Response $response): void
    {
        self::assertSame(500, $response->get_status());
        self::assertSame(
            [
                'code'    => 'wp_connections_internal_error',
                'message' => 'An internal error occurred.',
                'data'    => [ 'status' => 500 ],
            ],
            $response->get_data()
        );
    }
}
