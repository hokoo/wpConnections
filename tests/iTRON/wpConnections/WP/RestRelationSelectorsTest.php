<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Client;
use iTRON\wpConnections\Helpers\Database;
use iTRON\wpConnections\Query\Connection;
use iTRON\wpConnections\Query\Relation;
use iTRON\wpConnections\RestResponse\CollectionItem;

class RestRelationSelectorsTest extends WPConnectionsTestCase
{
    private const SELF_RELATION = 'rest06-self-relation';

    private ?Client $secondary_client = null;

    public function set_up()
    {
        parent::set_up();
        $this->register_relation($this->client, self::SELF_RELATION, 'post', 'post', true);
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

    public function test_get_relation_route_declares_positive_scalar_selectors(): void
    {
        $pattern = $this->get_rest_route('/relation/(?P<relation>[\w-]+)');
        $handlers = $this->rest_server->get_routes('wp-connections/v1')[ $pattern ];
        $get_handler = null;

        foreach ($handlers as $handler) {
            if (is_array($handler) && ! empty($handler['methods']['GET'])) {
                $get_handler = $handler;
                break;
            }
        }

        self::assertIsArray($get_handler);
        self::assertSame([ 'relation', 'from', 'to', 'both' ], array_keys($get_handler['args']));
        foreach ([ 'from', 'to', 'both' ] as $selector) {
            self::assertSame('integer', $get_handler['args'][ $selector ]['type']);
            self::assertSame(1, $get_handler['args'][ $selector ]['minimum']);
            self::assertFalse($get_handler['args'][ $selector ]['required']);
        }
    }

    public function test_dispatch_applies_selector_algebra_and_returns_empty_for_no_match(): void
    {
        $fixture = $this->create_selector_fixture();

        $cases = [
            'from' => [
                [ 'from' => $fixture['a'] ],
                [ $fixture['ax']->id, $fixture['ay']->id ],
            ],
            'to' => [
                [ 'to' => $fixture['x'] ],
                [ $fixture['ax']->id, $fixture['bx']->id ],
            ],
            'both from side' => [
                [ 'both' => $fixture['a'] ],
                [ $fixture['ax']->id, $fixture['ay']->id ],
            ],
            'both to side' => [
                [ 'both' => $fixture['x'] ],
                [ $fixture['ax']->id, $fixture['bx']->id ],
            ],
            'from and to' => [
                [ 'from' => $fixture['a'], 'to' => $fixture['x'] ],
                [ $fixture['ax']->id ],
            ],
            'from and both' => [
                [ 'from' => $fixture['a'], 'both' => $fixture['x'] ],
                [ $fixture['ax']->id ],
            ],
            'to and both' => [
                [ 'to' => $fixture['x'], 'both' => $fixture['b'] ],
                [ $fixture['bx']->id ],
            ],
            'all three' => [
                [
                    'from' => $fixture['a'],
                    'to'   => $fixture['x'],
                    'both' => $fixture['a'],
                ],
                [ $fixture['ax']->id ],
            ],
            'no match' => [
                [ 'from' => $fixture['a'], 'to' => $fixture['z'] ],
                [],
            ],
        ];

        foreach ($cases as $label => [ $query, $expected_ids ]) {
            $response = $this->dispatch_relation(RELATION_0_NAME, $query);
            self::assertSame(200, $response->get_status(), $label);
            $this->assert_response_connection_ids($expected_ids, $response, $label);
        }
    }

    public function test_both_selector_keeps_incident_and_self_connections(): void
    {
        $x = $this->post_ids[0];
        $y = $this->post_ids[1];
        $self = $this->create_connection($this->client, self::SELF_RELATION, $x, $x);
        $incident = $this->create_connection($this->client, self::SELF_RELATION, $x, $y);

        $response = $this->dispatch_relation(self::SELF_RELATION, [ 'both' => $x ]);

        self::assertSame(200, $response->get_status());
        $this->assert_response_connection_ids([ $self->id, $incident->id ], $response);
    }

    public function test_unfiltered_wire_and_path_ownership_remain_unchanged(): void
    {
        $fixture = $this->create_selector_fixture();
        $this->create_connection(
            $this->client,
            RELATION_1_NAME,
            $fixture['a'],
            $fixture['x']
        );
        $this->create_secondary_client_connection(
            $fixture['a'],
            $fixture['x']
        );

        $unfiltered = $this->dispatch_relation(RELATION_0_NAME);
        self::assertSame(200, $unfiltered->get_status());
        $this->assert_response_connection_ids(
            [
                $fixture['ax']->id,
                $fixture['ay']->id,
                $fixture['bx']->id,
                $fixture['bz']->id,
            ],
            $unfiltered
        );
        foreach ($unfiltered->get_data() as $item) {
            self::assertInstanceOf(CollectionItem::class, $item);
            self::assertSame(
                [ 'id', 'title', 'relation', 'from', 'to', 'order', 'meta' ],
                array_keys($item->get_data())
            );
            self::assertSame(RELATION_0_NAME, $item->get_data()['relation']);
        }

        $request = new \WP_REST_Request('GET', $this->relation_route(RELATION_0_NAME));
        $request->set_body_params(
            [
                'client'   => $this->secondary_client->getName(),
                'relation' => RELATION_1_NAME,
            ]
        );
        $request->set_query_params(
            [
                'client'   => $this->secondary_client->getName(),
                'relation' => RELATION_1_NAME,
                'from'     => (string) $fixture['a'],
            ]
        );
        $owned = $this->rest_server->dispatch($request);

        self::assertSame(200, $owned->get_status());
        $this->assert_response_connection_ids(
            [ $fixture['ax']->id, $fixture['ay']->id ],
            $owned
        );
    }

    /**
     * @dataProvider invalid_selector_provider
     */
    public function test_invalid_query_selector_fails_before_storage_sql(
        string $selector,
        $value
    ): void {
        $queries = [];
        $query_listener = static function ($sql) use (&$queries): void {
            $queries[] = $sql;
        };

        add_action('wpConnections/storage/findConnections/dbQuery', $query_listener, 10, 1);
        try {
            $response = $this->dispatch_relation(RELATION_0_NAME, [ $selector => $value ]);
        } finally {
            remove_action('wpConnections/storage/findConnections/dbQuery', $query_listener, 10);
        }

        $this->assert_invalid_selector_response($selector, $response);
        self::assertSame([], $queries);
        self::assertStringNotContainsString('SELECT ', wp_json_encode($response->get_data()));
    }

    public function invalid_selector_provider(): array
    {
        $invalid_values = [
            'empty string'       => '',
            'string zero'        => '0',
            'integer zero'       => 0,
            'negative string'    => '-1',
            'negative integer'   => -1,
            'fractional string'  => '1.5',
            'noninteger string'  => 'invalid',
            'overflow string'    => '9223372036854775808',
            'non-finite string'  => '1e999',
            'observable array'   => [ '1', '2' ],
            'observable null'    => null,
        ];
        $cases = [];

        foreach ([ 'from', 'to', 'both' ] as $selector) {
            foreach ($invalid_values as $label => $value) {
                $cases[ $selector . ' ' . $label ] = [ $selector, $value ];
            }
        }

        return $cases;
    }

    public function test_invalid_combination_fails_before_storage_sql(): void
    {
        $queries = [];
        $query_listener = static function ($sql) use (&$queries): void {
            $queries[] = $sql;
        };
        add_action('wpConnections/storage/findConnections/dbQuery', $query_listener, 10, 1);

        try {
            $response = $this->dispatch_relation(
                RELATION_0_NAME,
                [ 'from' => (string) $this->page_ids[0], 'both' => [ '1', '2' ] ]
            );
        } finally {
            remove_action('wpConnections/storage/findConnections/dbQuery', $query_listener, 10);
        }

        $this->assert_invalid_selector_response('both', $response);
        self::assertSame([], $queries);
    }

    public function test_valid_body_selector_cannot_mask_repeated_raw_query_selector(): void
    {
        $queries = [];
        $query_listener = static function ($sql) use (&$queries): void {
            $queries[] = $sql;
        };
        add_action('wpConnections/storage/findConnections/dbQuery', $query_listener, 10, 1);

        try {
            $response = $this->dispatch_raw_relation_query(
                RELATION_0_NAME,
                'from=11&from=22',
                [ 'from' => $this->page_ids[0] ]
            );
        } finally {
            remove_action('wpConnections/storage/findConnections/dbQuery', $query_listener, 10);
        }

        $this->assert_invalid_selector_response('from', $response);
        self::assertSame([], $queries);
    }

    /**
     * @dataProvider repeated_raw_selector_provider
     */
    public function test_raw_repeated_query_selector_becomes_native_invalid_array(
        string $raw_query,
        string $selector
    ): void {
        $queries = [];
        $query_listener = static function ($sql) use (&$queries): void {
            $queries[] = $sql;
        };
        add_action('wpConnections/storage/findConnections/dbQuery', $query_listener, 10, 1);

        try {
            $response = $this->dispatch_raw_relation_query(RELATION_0_NAME, $raw_query);
        } finally {
            remove_action('wpConnections/storage/findConnections/dbQuery', $query_listener, 10);
        }

        $this->assert_invalid_selector_response($selector, $response);
        self::assertSame([], $queries);
    }

    public function repeated_raw_selector_provider(): array
    {
        return [
            'plain duplicate' => [ 'from=11&from=22', 'from' ],
            'mixed scalar then bracket' => [ 'to=11&to[]=22', 'to' ],
            'mixed bracket then scalar' => [ 'both[]=11&both=22', 'both' ],
            'encoded selector key' => [ 'fr%6Fm=11&from=22', 'from' ],
            'PHP bracket suffix then scalar' => [ 'from[0]junk=11&from=22', 'from' ],
            'scalar then PHP bracket suffix' => [ 'from=22&from[0]junk=11', 'from' ],
            'encoded PHP bracket suffix then scalar' => [
                'from%5B0%5Djunk=11&from=22',
                'from',
            ],
            'scalar then encoded PHP bracket suffix' => [
                'from=22&from%5B0%5Djunk=11',
                'from',
            ],
        ];
    }

    public function test_raw_query_guard_ignores_wrong_route_method_and_client(): void
    {
        $fixture = $this->create_selector_fixture();
        $wrong_cases = [
            [ 'GET', $this->relation_route(RELATION_0_NAME) . '/' . $fixture['ax']->id ],
            [ 'POST', $this->relation_route(RELATION_0_NAME) ],
            [ 'GET', '/wp-connections/v1/client/not-this-client/relation/' . RELATION_0_NAME ],
        ];

        foreach ($wrong_cases as [ $method, $route ]) {
            $query = $this->run_raw_parse_request($method, $route, 'from=11&from=22');
            self::assertSame('22', $query['from']);
        }
    }

    public function test_raw_query_guard_uses_php_input_separator(): void
    {
        $separators = (string) ini_get('arg_separator.input');
        $separator = '' === $separators ? '&' : $separators[0];
        $query = $this->run_raw_parse_request(
            'GET',
            $this->relation_route(RELATION_0_NAME),
            'from=11' . $separator . 'from=22'
        );

        self::assertSame([ '11', '22' ], $query['from']);
    }

    public function test_raw_query_guard_ignores_other_site_context(): void
    {
        if (! is_multisite()) {
            self::markTestSkipped('Site-context ingress isolation requires multisite.');
        }

        $blog_id = self::factory()->blog->create();
        switch_to_blog($blog_id);
        try {
            $query = $this->run_raw_parse_request(
                'GET',
                $this->relation_route(RELATION_0_NAME),
                'from=11&from=22'
            );
        } finally {
            restore_current_blog();
        }

        self::assertSame('22', $query['from']);
    }

    public function test_direct_dispatch_does_not_read_ambient_raw_query(): void
    {
        $fixture = $this->create_selector_fixture();
        $server_query = $_SERVER['QUERY_STRING'] ?? null;
        $_SERVER['QUERY_STRING'] = 'from=999998&from=999999';

        try {
            $response = $this->dispatch_relation(
                RELATION_0_NAME,
                [ 'from' => (string) $fixture['a'] ]
            );
        } finally {
            $this->restore_server_value('QUERY_STRING', $server_query);
        }

        self::assertSame(200, $response->get_status());
        $this->assert_response_connection_ids(
            [ $fixture['ax']->id, $fixture['ay']->id ],
            $response
        );
    }

    private function create_selector_fixture(): array
    {
        $a = $this->page_ids[0];
        $b = $this->create_entity('page', 'REST06 page B');
        $x = $this->post_ids[0];
        $y = $this->post_ids[1];
        $z = $this->create_entity('post', 'REST06 post Z');

        return [
            'a'  => $a,
            'b'  => $b,
            'x'  => $x,
            'y'  => $y,
            'z'  => $z,
            'ax' => $this->create_connection($this->client, RELATION_0_NAME, $a, $x),
            'ay' => $this->create_connection($this->client, RELATION_0_NAME, $a, $y),
            'bx' => $this->create_connection($this->client, RELATION_0_NAME, $b, $x),
            'bz' => $this->create_connection($this->client, RELATION_0_NAME, $b, $z),
        ];
    }

    private function dispatch_relation(
        string $relation,
        array $query_params = [],
        array $body_params = []
    ): \WP_REST_Response {
        $request = new \WP_REST_Request('GET', $this->relation_route($relation));
        $request->set_body_params($body_params);
        $request->set_query_params($query_params);

        return $this->rest_server->dispatch($request);
    }

    private function dispatch_raw_relation_query(
        string $relation,
        string $raw_query,
        array $body_params = []
    ): \WP_REST_Response {
        $original_post = $_POST;
        $_POST = $body_params;

        try {
            return $this->with_raw_parse_request(
                'GET',
                $this->relation_route($relation),
                $raw_query,
                function () use ($relation): \WP_REST_Response {
                    $captured_response = null;
                    $pre_echo_data = null;
                    $capture = static function ($response) use (&$captured_response) {
                        $captured_response = rest_ensure_response($response);

                        return $response;
                    };
                    $capture_pre_echo = static function ($data) use (&$pre_echo_data) {
                        $pre_echo_data = $data;

                        return $data;
                    };

                    add_filter('rest_post_dispatch', $capture, PHP_INT_MAX, 1);
                    add_filter('rest_pre_echo_response', $capture_pre_echo, PHP_INT_MAX, 1);
                    ob_start();
                    try {
                        $this->rest_server->serve_request($this->relation_route($relation));
                        $emitted_wire = ob_get_contents();
                    } finally {
                        ob_end_clean();
                        remove_filter('rest_pre_echo_response', $capture_pre_echo, PHP_INT_MAX);
                        remove_filter('rest_post_dispatch', $capture, PHP_INT_MAX);
                    }

                    self::assertInstanceOf(\WP_REST_Response::class, $captured_response);
                    self::assertSame($captured_response->get_data(), $pre_echo_data);
                    $wire = '' === $emitted_wire ? wp_json_encode($pre_echo_data) : $emitted_wire;
                    self::assertJson($wire, var_export($wire, true));
                    self::assertSame($captured_response->get_data(), json_decode($wire, true));

                    return $captured_response;
                }
            );
        } finally {
            $_POST = $original_post;
        }
    }

    private function run_raw_parse_request(string $method, string $route, string $raw_query): array
    {
        return $this->with_raw_parse_request(
            $method,
            $route,
            $raw_query,
            static fn (): array => $_GET
        );
    }

    private function with_raw_parse_request(
        string $method,
        string $route,
        string $raw_query,
        callable $operation
    ): mixed {
        global $wp;

        $original_wp = $wp;
        $original_get = $_GET;
        $original_method = $_SERVER['REQUEST_METHOD'] ?? null;
        $original_query = $_SERVER['QUERY_STRING'] ?? null;
        $rest_api_priority = has_action('parse_request', 'rest_api_loaded');

        parse_str($raw_query, $_GET);
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['QUERY_STRING'] = $raw_query;
        $wp = new \WP();
        $wp->query_vars['rest_route'] = $route;

        if (false !== $rest_api_priority) {
            remove_action('parse_request', 'rest_api_loaded', $rest_api_priority);
        }

        try {
            do_action_ref_array('parse_request', [ &$wp ]);

            return $operation();
        } finally {
            if (false !== $rest_api_priority) {
                add_action('parse_request', 'rest_api_loaded', $rest_api_priority);
            }
            $wp = $original_wp;
            $_GET = $original_get;
            $this->restore_server_value('REQUEST_METHOD', $original_method);
            $this->restore_server_value('QUERY_STRING', $original_query);
        }
    }

    private function restore_server_value(string $key, $value): void
    {
        if (null === $value) {
            unset($_SERVER[ $key ]);
            return;
        }

        $_SERVER[ $key ] = $value;
    }

    private function relation_route(string $relation): string
    {
        return $this->get_rest_route('/relation/' . $relation);
    }

    private function create_connection(
        Client $client,
        string $relation,
        int $from,
        int $to
    ): \iTRON\wpConnections\Connection {
        return $client->getRelation($relation)->createConnection(new Connection($from, $to));
    }

    private function create_entity(string $post_type, string $title): int
    {
        return self::factory()->post->create(
            [
                'post_title'  => $title,
                'post_status' => 'publish',
                'post_type'   => $post_type,
            ]
        );
    }

    private function register_relation(
        Client $client,
        string $name,
        string $from_type,
        string $to_type,
        bool $closurable = false
    ): void {
        $relation = new Relation();
        $relation->set('name', $name);
        $relation->set('from', $from_type);
        $relation->set('to', $to_type);
        $relation->set('cardinality', 'm-m');
        $relation->set('closurable', $closurable);
        $client->registerRelation($relation);
    }

    private function create_secondary_client_connection(
        int $from,
        int $to
    ): \iTRON\wpConnections\Connection {
        $this->secondary_client = new Client('rest06-secondary-client');
        $this->register_relation($this->secondary_client, RELATION_0_NAME, 'page', 'post');

        return $this->create_connection($this->secondary_client, RELATION_0_NAME, $from, $to);
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

    private function assert_response_connection_ids(
        array $expected,
        \WP_REST_Response $response,
        string $message = ''
    ): void {
        $actual = $this->response_connection_ids($response);
        sort($expected, SORT_NUMERIC);
        sort($actual, SORT_NUMERIC);
        self::assertSame($expected, $actual, $message);
    }

    private function response_connection_ids(\WP_REST_Response $response): array
    {
        $ids = [];
        foreach ($response->get_data() as $item) {
            self::assertInstanceOf(CollectionItem::class, $item);
            $ids[] = $item->get_data()['id'];
        }

        return $ids;
    }

    private function assert_invalid_selector_response(
        string $selector,
        \WP_REST_Response $response
    ): void {
        self::assertSame(400, $response->get_status());
        self::assertSame('rest_invalid_param', $response->get_data()['code'] ?? null);
        self::assertArrayHasKey($selector, $response->get_data()['data']['params'] ?? []);
    }
}
