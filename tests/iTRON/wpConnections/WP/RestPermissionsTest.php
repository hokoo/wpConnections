<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Client;
use iTRON\wpConnections\Query\Connection;
use iTRON\wpConnections\Query\Relation;
use WP_REST_Request;
use WP_REST_Server;

/**
 * REST-PERM-01 differentiated permission contract.
 */
class RestPermissionsTest extends WPConnectionsTestCase
{
    private const CALLBACK_CAPABILITY = 'manage_wpconnections_rest04_callback';
    private const DEFAULT_CAPABILITY = 'manage_wpconnections_rest04_default';
    private const READ_CAPABILITY = 'read_wpconnections_rest04';
    private const WRITE_CAPABILITY = 'write_wpconnections_rest04';
    private const SECONDARY_CLIENT = 'rest04-secondary-client';

    private array $capability_filters = [];
    private array $secondary_clients = [];
    private $rest_api_filter;
    private $storage_filter;

    public function set_up()
    {
        RestPermissionRecordingRestApi::reset();
        $this->capability_filters = [];
        $this->secondary_clients = [];
        $this->rest_api_filter = static function (): string {
            return RestPermissionRecordingRestApi::class;
        };
        $this->storage_filter = static function (string $default, Client $client): string {
            if (self::SECONDARY_CLIENT === $client->getName()) {
                return RestPermissionMemoryStorage::class;
            }

            return $default;
        };

        add_filter('wpConnections/factory/getRestApi/class', $this->rest_api_filter, 10, 2);
        add_filter('wpConnections/factory/getStorage/class', $this->storage_filter, 10, 2);
        parent::set_up();
        $this->set_up_rest_server();
    }

    public function tear_down()
    {
        try {
            foreach ($this->secondary_clients as $client) {
                $client->dispose();
            }
            foreach ($this->capability_filters as [ $hook, $filter ]) {
                remove_filter($hook, $filter, 10);
            }
            remove_filter('wpConnections/factory/getRestApi/class', $this->rest_api_filter, 10);
            remove_filter('wpConnections/factory/getStorage/class', $this->storage_filter, 10);
            $this->tear_down_rest_server();
        } finally {
            parent::tear_down();
        }
    }

    /**
     * @dataProvider route_variant_provider
     */
    public function test_manage_options_is_the_default_for_every_route_variant(
        string $handler,
        string $method,
        string $variant
    ): void {
        [ $route, $payload ] = $this->prepare_variant($variant);
        $user = $this->authenticate_as_subscriber();
        $before = $this->persisted_state();
        RestPermissionRecordingRestApi::$handler_calls = [];

        $denied = $this->dispatch_rest_request($method, $route, $payload);

        $this->assert_forbidden(403, $denied);
        self::assertSame([], RestPermissionRecordingRestApi::$handler_calls);
        self::assertSame($before, $this->persisted_state());

        $user->add_cap('manage_options');
        wp_set_current_user($user->ID);
        $allowed = $this->dispatch_rest_request($method, $route, $payload);

        self::assertSame(200, $allowed->get_status());
        self::assertSame([ [ $handler, $method ] ], RestPermissionRecordingRestApi::$handler_calls);
    }

    /**
     * @dataProvider route_variant_provider
     */
    public function test_client_filtered_default_is_used_by_every_unoverridden_route_variant(
        string $handler,
        string $method,
        string $variant
    ): void {
        $this->replace_client_with_filtered_default();
        [ $route, $payload ] = $this->prepare_variant($variant);
        $user = $this->authenticate_as_subscriber();
        $before = $this->persisted_state();
        RestPermissionRecordingRestApi::$handler_calls = [];

        $denied = $this->dispatch_rest_request($method, $route, $payload);

        $this->assert_forbidden(403, $denied);
        self::assertSame([], RestPermissionRecordingRestApi::$handler_calls);
        self::assertSame($before, $this->persisted_state());

        $user->add_cap(self::DEFAULT_CAPABILITY);
        wp_set_current_user($user->ID);
        $allowed = $this->dispatch_rest_request($method, $route, $payload);

        self::assertSame(200, $allowed->get_status());
        self::assertSame([ [ $handler, $method ] ], RestPermissionRecordingRestApi::$handler_calls);
    }

    /**
     * @dataProvider route_variant_provider
     */
    public function test_per_callback_override_allows_and_denies_every_route_variant(
        string $handler,
        string $method,
        string $variant
    ): void {
        $this->client->capabilities->{$handler} = self::CALLBACK_CAPABILITY;
        [ $route, $payload ] = $this->prepare_variant($variant);
        $user = $this->authenticate_as_subscriber();
        $before = $this->persisted_state();
        RestPermissionRecordingRestApi::$handler_calls = [];

        $denied = $this->dispatch_rest_request($method, $route, $payload);

        $this->assert_forbidden(403, $denied);
        self::assertSame([], RestPermissionRecordingRestApi::$handler_calls);
        self::assertSame($before, $this->persisted_state());

        $user->add_cap(self::CALLBACK_CAPABILITY);
        wp_set_current_user($user->ID);
        $allowed = $this->dispatch_rest_request($method, $route, $payload);

        self::assertSame(200, $allowed->get_status());
        self::assertSame([ [ $handler, $method ] ], RestPermissionRecordingRestApi::$handler_calls);
    }

    public function test_read_only_policy_allows_gets_and_denies_valid_mutations_before_handlers(): void
    {
        foreach ([ 'getTheClient', 'getRelation', 'getConnection' ] as $handler) {
            $this->client->capabilities->{$handler} = self::READ_CAPABILITY;
        }
        foreach (
            [
            'createConnection',
            'updateConnection',
            'deleteConnection',
            'updateConnectionMeta',
            'deleteConnectionMeta',
            ] as $handler
        ) {
            $this->client->capabilities->{$handler} = self::WRITE_CAPABILITY;
        }

        $user = $this->authenticate_as_subscriber();
        $user->add_cap(self::READ_CAPABILITY);
        wp_set_current_user($user->ID);
        $connection = $this->create_persisted_connection();
        $relation_route = $this->relation_route();
        $connection_route = $relation_route . '/' . $connection->id;
        $meta_route = $connection_route . '/meta';

        foreach (
            [
            [ 'GET', $this->get_rest_route(), [] ],
            [ 'GET', $relation_route, [] ],
            [ 'GET', $connection_route, [] ],
            ] as [ $method, $route, $payload ]
        ) {
            self::assertSame(200, $this->dispatch_rest_request($method, $route, $payload)->get_status());
        }

        $before = $this->persisted_state();
        RestPermissionRecordingRestApi::$handler_calls = [];
        foreach (
            [
            [ 'POST', $relation_route, $this->create_payload() ],
            [ 'PATCH', $connection_route, [ 'title' => 'denied update' ] ],
            [ 'DELETE', $connection_route, [] ],
            [ 'POST', $meta_route, $this->meta_payload() ],
            [ 'DELETE', $meta_route, $this->meta_payload() ],
            ] as [ $method, $route, $payload ]
        ) {
            $this->assert_forbidden(
                403,
                $this->dispatch_rest_request($method, $route, $payload)
            );
        }

        self::assertSame([], RestPermissionRecordingRestApi::$handler_calls);
        self::assertSame($before, $this->persisted_state());
    }

    public function test_denial_uses_native_anonymous_and_authenticated_shapes(): void
    {
        $this->authenticate_as_anonymous();
        $this->assert_forbidden(
            401,
            $this->dispatch_rest_request('GET', $this->get_rest_route())
        );

        $this->authenticate_as_subscriber();
        $this->assert_forbidden(
            403,
            $this->dispatch_rest_request('GET', $this->get_rest_route())
        );
    }

    public function test_unknown_callback_falls_back_to_default_without_widening_managed_boundary(): void
    {
        $this->replace_client_with_filtered_default();
        $delegate = RestPermissionRecordingRestApi::$delegates[ $this->client->getName() ];
        $user = $this->authenticate_as_subscriber();
        $request = new WP_REST_Request('GET', $this->get_rest_route());
        $request->set_attributes([ 'callback' => [ $delegate, 'unknownCallback' ] ]);

        self::assertFalse($delegate->checkPermissions($request));
        $user->add_cap(self::DEFAULT_CAPABILITY);
        wp_set_current_user($user->ID);
        self::assertTrue($delegate->checkPermissions($request));

        $registered = $this->rest_server->get_routes()[ $this->get_rest_route() ];
        $boundary = $registered[0]['callback'][0];
        $unknown_route = $this->get_rest_route('/unknown-permission-handler');
        $this->rest_server->register_route(
            'wp-connections/v1',
            $unknown_route,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $boundary, 'checkPermissions' ],
                    'permission_callback' => [ $boundary, 'checkPermissions' ],
                ],
            ],
            true
        );

        $response = $this->dispatch_rest_request('GET', $unknown_route);
        self::assertSame(404, $response->get_status());
        self::assertSame('rest_no_route', $response->get_data()['code'] ?? null);
    }

    public function test_callback_override_is_isolated_to_its_client(): void
    {
        $this->client->capabilities->getTheClient = self::CALLBACK_CAPABILITY;
        $secondary = new Client(self::SECONDARY_CLIENT);
        $this->secondary_clients[] = $secondary;
        $user = $this->authenticate_as_subscriber();
        $user->add_cap(self::CALLBACK_CAPABILITY);
        wp_set_current_user($user->ID);

        self::assertSame(
            200,
            $this->dispatch_rest_request('GET', $this->get_rest_route())->get_status()
        );
        $this->assert_forbidden(
            403,
            $this->dispatch_rest_request(
                'GET',
                '/wp-connections/v1/client/' . self::SECONDARY_CLIENT
            )
        );
    }

    public function route_variant_provider(): array
    {
        return [
            'client GET'          => [ 'getTheClient', 'GET', 'client_get' ],
            'relation GET'        => [ 'getRelation', 'GET', 'relation_get' ],
            'relation POST'       => [ 'createConnection', 'POST', 'relation_post' ],
            'connection GET'      => [ 'getConnection', 'GET', 'connection_get' ],
            'connection POST'     => [ 'updateConnection', 'POST', 'connection_post' ],
            'connection PUT'      => [ 'updateConnection', 'PUT', 'connection_put' ],
            'connection PATCH'    => [ 'updateConnection', 'PATCH', 'connection_patch' ],
            'connection DELETE'   => [ 'deleteConnection', 'DELETE', 'connection_delete' ],
            'meta POST'           => [ 'updateConnectionMeta', 'POST', 'meta_post' ],
            'meta PUT'            => [ 'updateConnectionMeta', 'PUT', 'meta_put' ],
            'meta PATCH'          => [ 'updateConnectionMeta', 'PATCH', 'meta_patch' ],
            'meta DELETE'         => [ 'deleteConnectionMeta', 'DELETE', 'meta_delete' ],
        ];
    }

    private function prepare_variant(string $variant): array
    {
        $relation_route = $this->relation_route();
        if ('client_get' === $variant) {
            return [ $this->get_rest_route(), [] ];
        }
        if ('relation_get' === $variant) {
            return [ $relation_route, [] ];
        }
        if ('relation_post' === $variant) {
            return [ $relation_route, $this->create_payload() ];
        }

        $connection = $this->create_persisted_connection();
        $connection_route = $relation_route . '/' . $connection->id;
        if ('connection_get' === $variant || 'connection_delete' === $variant) {
            return [ $connection_route, [] ];
        }
        if ('connection_post' === $variant || 'connection_put' === $variant) {
            return [ $connection_route, $this->create_payload('allowed replacement') ];
        }
        if ('connection_patch' === $variant) {
            return [ $connection_route, [ 'title' => 'allowed partial update' ] ];
        }

        $meta_route = $connection_route . '/meta';

        return [ $meta_route, $this->meta_payload() ];
    }

    private function replace_client_with_filtered_default(): void
    {
        $this->client->dispose();
        $hook = 'wpConnections/client/' . CLIENT_NAME . '/clientDefaultCapabilities';
        $filter = static function (): string {
            return self::DEFAULT_CAPABILITY;
        };
        add_filter($hook, $filter, 10, 1);
        $this->capability_filters[] = [ $hook, $filter ];
        $this->client = new Client(CLIENT_NAME);
        $this->register_test_relation();
    }

    private function register_test_relation(): void
    {
        $relation = new Relation();
        $relation->set('name', RELATION_0_NAME);
        $relation->set('from', 'page');
        $relation->set('to', 'post');
        $relation->set('cardinality', 'm-m');
        $this->client->registerRelation($relation);
    }

    private function authenticate_as_subscriber(): \WP_User
    {
        $user_id = self::factory()->user->create([ 'role' => 'subscriber' ]);
        wp_set_current_user($user_id);

        return wp_get_current_user();
    }

    private function create_persisted_connection(): \iTRON\wpConnections\Connection
    {
        $query = new Connection($this->page_ids[0], $this->post_ids[0]);
        $query->set('title', 'permission fixture');
        $query->meta->fromArray([ [ 'key' => 'permission', 'value' => 'original' ] ]);

        return $this->client->getRelation(RELATION_0_NAME)->createConnection($query);
    }

    private function create_payload(string $title = 'allowed create'): array
    {
        return [
            'from'  => $this->page_ids[0],
            'to'    => $this->post_ids[1],
            'title' => $title,
        ];
    }

    private function meta_payload(): array
    {
        return [
            'meta' => [
                [ 'key' => 'permission', 'value' => 'allowed' ],
            ],
        ];
    }

    private function relation_route(): string
    {
        return $this->get_rest_route('/relation/' . RELATION_0_NAME);
    }

    private function persisted_state(): array
    {
        return $this->client->getRelation(RELATION_0_NAME)->findConnections()->toArray();
    }

    private function assert_forbidden(int $status, \WP_REST_Response $response): void
    {
        $data = $response->get_data();

        self::assertSame($status, $response->get_status());
        self::assertSame('rest_forbidden', $data['code'] ?? null);
        self::assertSame($status, $data['data']['status'] ?? null);
    }
}
