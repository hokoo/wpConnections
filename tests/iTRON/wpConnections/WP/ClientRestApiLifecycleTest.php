<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Abstracts\Connection as AbstractConnection;
use iTRON\wpConnections\Abstracts\Storage;
use iTRON\wpConnections\Client;
use iTRON\wpConnections\ClientRestApi;
use iTRON\wpConnections\ConnectionCollection;
use iTRON\wpConnections\Exceptions\ClientRegisterFail;
use iTRON\wpConnections\Internal\RestRouteRegistry;
use iTRON\wpConnections\MetaCollection;
use iTRON\wpConnections\Query\Connection as ConnectionQuery;
use iTRON\wpConnections\Query\MetaCollection as MetaQueryCollection;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class RestHookMemoryStorage extends Storage
{
	public function __construct( Client $client )
	{
	}

	public function createConnection( ConnectionQuery $connection_query ): int
	{
		return 1;
	}

	public function updateConnection( AbstractConnection $connection ): bool
	{
		return true;
	}

	public function deleteSpecificConnections( $connection_ids ): int
	{
		return 0;
	}

	public function deleteByObjectID(
		$object_ids,
		string $relation = '',
		bool $only_from = false,
		bool $only_to = false
	): int {
		return 0;
	}

	public function deleteDirectedConnections(
		?int $from = null,
		?int $to = null,
		string $relation = ''
	): int {
		return 0;
	}

	public function findConnections( ConnectionQuery $params ): ConnectionCollection
	{
		return new ConnectionCollection();
	}

	public function addConnectionMeta( int $object_id, MetaCollection $meta_collection ): void
	{
	}

	public function removeConnectionMeta( int $object_id, MetaQueryCollection $meta_query )
	{
		return 0;
	}
}

class RestHookRecordingRestApi extends ClientRestApi
{
	public const NAMESPACE = 'wp-connections-managed/v9';
	public const BASE = 'managed-client';

	public static array $instances = [];
	public static array $init_calls = [];
	public static array $trace = [];
	public static int $legacy_registration_calls = 0;
	public static bool $enforce_native_permissions = true;

	public function __construct( Client $client )
	{
		parent::__construct( $client );

		$this->namespace = self::NAMESPACE;
		$this->base = self::BASE;
		self::$instances[] = $this;
	}

	public static function reset(): void
	{
		self::$instances = [];
		self::$init_calls = [];
		self::$trace = [];
		self::$legacy_registration_calls = 0;
		self::$enforce_native_permissions = true;
	}

	public function init()
	{
		$object_id = spl_object_id( $this );
		self::$init_calls[ $object_id ] = ( self::$init_calls[ $object_id ] ?? 0 ) + 1;

		parent::init();
	}

	public function registerRestRoutes()
	{
		self::$legacy_registration_calls++;

		return parent::registerRestRoutes();
	}

	public function checkPermissions( WP_REST_Request $request ): bool
	{
		$callback = $request->get_attributes()['callback'] ?? null;
		$handler = is_array( $callback ) && isset( $callback[1] ) ? $callback[1] : null;
		$this->record( 'permission', $handler );

		if ( ! self::$enforce_native_permissions ) {
			return true;
		}

		return parent::checkPermissions( $request );
	}

	public function getTheClient( WP_REST_Request $request )
	{
		return $this->record_handler( __FUNCTION__, $request );
	}

	public function getRelation( WP_REST_Request $request )
	{
		return $this->record_handler( __FUNCTION__, $request );
	}

	public function createConnection( WP_REST_Request $request )
	{
		return $this->record_handler( __FUNCTION__, $request );
	}

	public function getConnection( WP_REST_Request $request )
	{
		return $this->record_handler( __FUNCTION__, $request );
	}

	public function updateConnection( WP_REST_Request $request )
	{
		return $this->record_handler( __FUNCTION__, $request );
	}

	public function deleteConnection( WP_REST_Request $request )
	{
		return $this->record_handler( __FUNCTION__, $request );
	}

	public function updateConnectionMeta( WP_REST_Request $request )
	{
		return $this->record_handler( __FUNCTION__, $request );
	}

	public function deleteConnectionMeta( WP_REST_Request $request )
	{
		return $this->record_handler( __FUNCTION__, $request );
	}

	private function record_handler( string $handler, WP_REST_Request $request ): WP_REST_Response
	{
		$this->record( 'handler', $handler );

		return rest_ensure_response(
			[
				'client_object_id' => spl_object_id( $this->getClient() ),
				'delegate_object_id' => spl_object_id( $this ),
				'handler' => $handler,
				'method' => $request->get_method(),
			]
		);
	}

	private function record( string $stage, ?string $handler ): void
	{
		global $wpdb;

		self::$trace[] = [
			'blog_id' => get_current_blog_id(),
			'prefix' => $wpdb->prefix,
			'delegate_object_id' => spl_object_id( $this ),
			'stage' => $stage,
			'handler' => $handler,
		];
	}
}

class RestHookMissingParentRestApi extends ClientRestApi
{
	public static array $instances = [];
	public static int $init_calls = 0;

	public function __construct( Client $client )
	{
		parent::__construct( $client );

		$this->namespace = RestHookRecordingRestApi::NAMESPACE;
		$this->base = RestHookRecordingRestApi::BASE;
		self::$instances[] = $this;
	}

	public static function reset(): void
	{
		self::$instances = [];
		self::$init_calls = 0;
	}

	public function init()
	{
		self::$init_calls++;
	}
}

/**
 * REST-HOOK-01 context, route-registry and factory-delegate contract.
 */
class ClientRestApiLifecycleTest extends \WP_UnitTestCase
{
	private const TEST_CAPABILITY = 'manage_wp_connections_rest_hook_test';
	private const DUPLICATE_MESSAGE = 'A REST API client is already registered for this WordPress site context.';
	private const MISSING_PARENT_MESSAGE = 'A custom REST API must call parent::init() to activate managed routes.';

	private array $clients = [];
	private array $factory_calls = [];
	private string $rest_api_class = RestHookRecordingRestApi::class;
	private $storage_filter;
	private $rest_api_filter;

	public function set_up()
	{
		parent::set_up();

		$this->clients = [];
		$this->factory_calls = [];
		$this->rest_api_class = RestHookRecordingRestApi::class;
		$GLOBALS['wp_rest_server'] = null;
		RestHookRecordingRestApi::reset();
		RestHookMissingParentRestApi::reset();

		$this->storage_filter = static function (): string {
			return RestHookMemoryStorage::class;
		};
		$this->rest_api_filter = function ( string $default_class, Client $client ): string {
			$this->factory_calls[] = [ $default_class, $client ];

			return $this->rest_api_class;
		};

		add_filter( 'wpConnections/factory/getStorage/class', $this->storage_filter, 10, 2 );
		add_filter( 'wpConnections/factory/getRestApi/class', $this->rest_api_filter, 10, 2 );
	}

	public function tear_down()
	{
		try {
			while ( function_exists( 'ms_is_switched' ) && ms_is_switched() ) {
				restore_current_blog();
			}

			foreach ( array_merge( RestHookRecordingRestApi::$instances, RestHookMissingParentRestApi::$instances ) as $rest_api ) {
				if ( method_exists( $rest_api, 'deactivate' ) ) {
					$rest_api->deactivate();
				}
			}

			if ( class_exists( RestRouteRegistry::class ) ) {
				foreach ( $this->clients as $client ) {
					RestRouteRegistry::instance()->deactivateClient( $client );
				}
			}

			foreach ( $this->clients as $client ) {
				$client->disablePostDeletionCleanup();
			}

			remove_filter( 'wpConnections/factory/getRestApi/class', $this->rest_api_filter, 10 );
			remove_filter( 'wpConnections/factory/getStorage/class', $this->storage_filter, 10 );
			$GLOBALS['wp_rest_server'] = null;
			wp_set_current_user( 0 );
		} finally {
			parent::tear_down();
		}
	}

	public function test_custom_delegate_preserves_exact_managed_route_contract(): void
	{
		$client = $this->new_client( 'custom-route-owner' );
		$delegate = $this->delegate_for_client( $client );
		$server = rest_get_server();

		self::assertSame( [ [ ClientRestApi::class, $client ] ], $this->factory_calls );
		self::assertSame( 1, RestHookRecordingRestApi::$init_calls[ spl_object_id( $delegate ) ] ?? 0 );
		self::assertSame( 0, RestHookRecordingRestApi::$legacy_registration_calls );
		$this->assert_exact_route_contract( $server, $delegate );
		self::assertArrayNotHasKey(
			'/wp-connections/v1/client/' . $client->getName(),
			$server->get_routes()
		);

		$this->authenticate_for_managed_routes();
		foreach ( $this->request_matrix( $delegate ) as $case ) {
			$this->assert_dispatches_to_delegate( $server, $delegate, $case );
		}
	}

	public function test_duplicate_identity_fails_stably_and_internal_revoke_allows_replacement(): void
	{
		$first_client = $this->new_client( 'duplicate-route-owner' );
		$first_delegate = $this->delegate_for_client( $first_client );
		$server = rest_get_server();

		$exception = $this->capture_client_registration_failure(
			static function (): void {
				new Client( 'duplicate-route-owner' );
			}
		);
		self::assertSame( 4, $exception->getCode() );
		self::assertSame( self::DUPLICATE_MESSAGE, $exception->getMessage() );

		$this->authenticate_for_managed_routes();
		$this->assert_dispatches_to_delegate( $server, $first_delegate, $this->request_matrix( $first_delegate )[0] );

		$first_delegate->deactivate();
		$first_delegate->deactivate();

		$replacement_client = $this->new_client( 'duplicate-route-owner' );
		$replacement_delegate = $this->delegate_for_client( $replacement_client );
		self::assertNotSame( $first_delegate, $replacement_delegate );
		$this->assert_exact_route_contract( $server, $replacement_delegate );
		$this->assert_dispatches_to_delegate(
			$server,
			$replacement_delegate,
			$this->request_matrix( $replacement_delegate )[0]
		);
	}

	public function test_client_created_after_rest_initialization_binds_to_existing_server(): void
	{
		$before_init = did_action( 'rest_api_init' );
		$server = rest_get_server();
		$after_init = did_action( 'rest_api_init' );
		self::assertGreaterThan( $before_init, $after_init );

		$client = $this->new_client( 'late-route-owner' );
		$delegate = $this->delegate_for_client( $client );

		self::assertSame( $after_init, did_action( 'rest_api_init' ) );
		self::assertSame( $server, rest_get_server() );
		self::assertSame( 1, RestHookRecordingRestApi::$init_calls[ spl_object_id( $delegate ) ] ?? 0 );
		$this->assert_exact_route_contract( $server, $delegate );

		$this->authenticate_for_managed_routes();
		$this->assert_dispatches_to_delegate( $server, $delegate, $this->request_matrix( $delegate )[0] );
	}

	public function test_no_owner_preserves_native_validation_precedence_and_route_discovery(): void
	{
		$client = $this->new_client( 'unavailable-route-owner' );
		$delegate = $this->delegate_for_client( $client );
		$server = rest_get_server();
		$patterns = array_keys( $this->expected_route_methods( $delegate ) );

		$delegate->deactivate();
		$delegate->deactivate();

		$routes = $server->get_routes();
		foreach ( $patterns as $pattern ) {
			self::assertArrayHasKey( $pattern, $routes );
		}

		$index_request = new WP_REST_Request( 'GET', '/' . $delegate->namespace );
		$index_response = $server->dispatch( $index_request );
		self::assertSame( 200, $index_response->get_status() );
		foreach ( $patterns as $pattern ) {
			self::assertArrayHasKey( $pattern, $index_response->get_data()['routes'] );
		}

		RestHookRecordingRestApi::$trace = [];
		$valid_response = $this->dispatch_case( $server, $this->request_matrix( $delegate )[0] );
		$this->assert_native_rest_error( 'rest_no_route', 404, $valid_response );
		self::assertSame( [], RestHookRecordingRestApi::$trace );

		$malformed_request = new WP_REST_Request(
			'POST',
			$this->client_route( $delegate ) . '/relation/example-relation'
		);
		$malformed_response = $server->dispatch( $malformed_request );
		$this->assert_native_rest_error( 'rest_missing_callback_param', 400, $malformed_response );
		self::assertSame( [ 'from', 'to' ], $malformed_response->get_data()['data']['params'] );
		self::assertSame( [], RestHookRecordingRestApi::$trace );
	}

	public function test_repeated_rest_initialization_does_not_duplicate_managed_routes(): void
	{
		$client = $this->new_client( 'repeated-route-owner' );
		$delegate = $this->delegate_for_client( $client );
		$server = rest_get_server();

		do_action( 'rest_api_init', $server );
		do_action( 'rest_api_init', $server );

		self::assertSame( 1, RestHookRecordingRestApi::$init_calls[ spl_object_id( $delegate ) ] ?? 0 );
		self::assertSame( 0, RestHookRecordingRestApi::$legacy_registration_calls );
		$this->assert_exact_route_contract( $server, $delegate );

		$this->authenticate_for_managed_routes();
		$this->assert_dispatches_to_delegate( $server, $delegate, $this->request_matrix( $delegate )[0] );
	}

	public function test_custom_init_without_parent_fails_without_claiming_route_identity(): void
	{
		$server = rest_get_server();
		$this->rest_api_class = RestHookMissingParentRestApi::class;

		$exception = $this->capture_client_registration_failure(
			static function (): void {
				new Client( 'missing-parent-route-owner' );
			}
		);
		self::assertSame( 4, $exception->getCode() );
		self::assertSame( self::MISSING_PARENT_MESSAGE, $exception->getMessage() );
		self::assertSame( 1, RestHookMissingParentRestApi::$init_calls );
		self::assertArrayNotHasKey(
			'/' . RestHookRecordingRestApi::NAMESPACE . '/' . RestHookRecordingRestApi::BASE .
			'/missing-parent-route-owner',
			$server->get_routes()
		);

		$this->rest_api_class = RestHookRecordingRestApi::class;
		$replacement = $this->new_client( 'missing-parent-route-owner' );
		$delegate = $this->delegate_for_client( $replacement );
		$this->assert_exact_route_contract( $server, $delegate );
	}

	public function test_actual_blog_switch_routes_same_name_clients_through_one_server(): void
	{
		if ( ! function_exists( 'switch_to_blog' ) && defined( 'ABSPATH' ) && defined( 'WPINC' ) ) {
			require_once ABSPATH . WPINC . '/ms-blogs.php';
		}

		if ( ! function_exists( 'switch_to_blog' ) || ! function_exists( 'restore_current_blog' ) ) {
			self::markTestSkipped( 'WordPress blog-switching functions are unavailable.' );
		}

		global $wpdb;

		RestHookRecordingRestApi::$enforce_native_permissions = false;
		$first_client = $this->new_client( 'switched-route-owner' );
		$first_delegate = $this->delegate_for_client( $first_client );
		$first_blog_id = get_current_blog_id();
		$first_prefix = $wpdb->prefix;
		$server = rest_get_server();

		$second_blog_id = is_multisite() ? self::factory()->blog->create() : $first_blog_id + 37;
		switch_to_blog( $second_blog_id );
		try {
			self::assertSame( $second_blog_id, get_current_blog_id() );
			$second_client = $this->new_client( 'switched-route-owner' );
			$second_delegate = $this->delegate_for_client( $second_client );
			$second_prefix = $wpdb->prefix;
			if ( is_multisite() ) {
				self::assertNotSame( $first_prefix, $second_prefix );
			}
			self::assertSame( $server, rest_get_server() );

			$this->assert_exact_route_contract( $server, $second_delegate );
			foreach ( $this->request_matrix( $second_delegate ) as $case ) {
				$this->assert_dispatches_to_delegate(
					$server,
					$second_delegate,
					$case,
					$second_blog_id,
					$second_prefix
				);
			}
		} finally {
			restore_current_blog();
		}

		self::assertSame( $first_blog_id, get_current_blog_id() );
		self::assertSame( $first_prefix, $wpdb->prefix );
		foreach ( $this->request_matrix( $first_delegate ) as $case ) {
			$this->assert_dispatches_to_delegate(
				$server,
				$first_delegate,
				$case,
				$first_blog_id,
				$first_prefix
			);
		}
	}

	private function new_client( string $name ): Client
	{
		$client = new Client( $name );
		$this->clients[] = $client;

		foreach ( $this->handler_names() as $handler ) {
			$client->capabilities->{$handler} = self::TEST_CAPABILITY;
		}

		return $client;
	}

	private function delegate_for_client( Client $client ): RestHookRecordingRestApi
	{
		foreach ( RestHookRecordingRestApi::$instances as $delegate ) {
			if ( $delegate->getClient() === $client ) {
				return $delegate;
			}
		}

		self::fail( 'The factory did not create a recording REST API delegate for the Client.' );
	}

	private function authenticate_for_managed_routes(): void
	{
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$user = get_user_by( 'id', $user_id );
		self::assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( self::TEST_CAPABILITY );
		wp_set_current_user( $user_id );
	}

	private function handler_names(): array
	{
		return [
			'getTheClient',
			'getRelation',
			'createConnection',
			'getConnection',
			'updateConnection',
			'deleteConnection',
			'updateConnectionMeta',
			'deleteConnectionMeta',
		];
	}

	private function request_matrix( ClientRestApi $delegate ): array
	{
		$client_route = $this->client_route( $delegate );
		$relation_route = $client_route . '/relation/example-relation';
		$connection_route = $relation_route . '/17';
		$meta_route = $connection_route . '/meta';
		$connection_payload = [ 'from' => 11, 'to' => 22 ];

		return [
			[ 'method' => 'GET', 'route' => $client_route, 'payload' => [], 'handler' => 'getTheClient' ],
			[ 'method' => 'GET', 'route' => $relation_route, 'payload' => [], 'handler' => 'getRelation' ],
			[ 'method' => 'POST', 'route' => $relation_route, 'payload' => $connection_payload, 'handler' => 'createConnection' ],
			[ 'method' => 'GET', 'route' => $connection_route, 'payload' => [], 'handler' => 'getConnection' ],
			[ 'method' => 'POST', 'route' => $connection_route, 'payload' => $connection_payload, 'handler' => 'updateConnection' ],
			[ 'method' => 'PUT', 'route' => $connection_route, 'payload' => $connection_payload, 'handler' => 'updateConnection' ],
			[ 'method' => 'PATCH', 'route' => $connection_route, 'payload' => $connection_payload, 'handler' => 'updateConnection' ],
			[ 'method' => 'DELETE', 'route' => $connection_route, 'payload' => [], 'handler' => 'deleteConnection' ],
			[ 'method' => 'POST', 'route' => $meta_route, 'payload' => [ 'meta' => [] ], 'handler' => 'updateConnectionMeta' ],
			[ 'method' => 'PUT', 'route' => $meta_route, 'payload' => [ 'meta' => [] ], 'handler' => 'updateConnectionMeta' ],
			[ 'method' => 'PATCH', 'route' => $meta_route, 'payload' => [ 'meta' => [] ], 'handler' => 'updateConnectionMeta' ],
			[ 'method' => 'DELETE', 'route' => $meta_route, 'payload' => [], 'handler' => 'deleteConnectionMeta' ],
		];
	}

	private function client_route( ClientRestApi $delegate ): string
	{
		return '/' . $delegate->namespace . '/' . $delegate->base . '/' . $delegate->getClient()->getName();
	}

	private function expected_route_methods( ClientRestApi $delegate ): array
	{
		$client_route = $this->client_route( $delegate );
		$relation_pattern = $client_route . '/relation/(?P<relation>[\w-]+)';
		$connection_pattern = $relation_pattern . '/(?P<connectionID>[\d]+)';

		return [
			$client_route => [ 'GET' ],
			$relation_pattern => [ 'GET', 'POST' ],
			$connection_pattern => [ 'DELETE', 'GET', 'PATCH', 'POST', 'PUT' ],
			$connection_pattern . '/meta' => [ 'DELETE', 'PATCH', 'POST', 'PUT' ],
		];
	}

	private function assert_exact_route_contract( WP_REST_Server $server, ClientRestApi $delegate ): void
	{
		$routes = $server->get_routes();
		$combination_count = 0;

		foreach ( $this->expected_route_methods( $delegate ) as $pattern => $expected_methods ) {
			self::assertArrayHasKey( $pattern, $routes );
			$actual_methods = [];

			foreach ( $routes[ $pattern ] as $handler ) {
				if ( ! is_array( $handler ) || empty( $handler['callback'] ) ) {
					continue;
				}

				self::assertTrue( is_callable( $handler['callback'] ) );
				self::assertTrue( is_callable( $handler['permission_callback'] ?? null ) );
				$this->assert_context_neutral_callback( $handler['callback'] );
				$this->assert_context_neutral_callback( $handler['permission_callback'] );

				foreach ( array_keys( array_filter( $handler['methods'] ?? [] ) ) as $method ) {
					$actual_methods[] = $method;
					$combination_count++;
				}
			}

			sort( $actual_methods );
			self::assertSame( $expected_methods, $actual_methods, 'Unexpected methods for ' . $pattern );
		}

		self::assertSame( 12, $combination_count );
	}

	private function assert_context_neutral_callback( $callback ): void
	{
		if ( is_array( $callback ) && isset( $callback[0] ) ) {
			self::assertNotInstanceOf( ClientRestApi::class, $callback[0] );
		}
	}

	private function assert_dispatches_to_delegate(
		WP_REST_Server $server,
		RestHookRecordingRestApi $delegate,
		array $case,
		?int $expected_blog_id = null,
		?string $expected_prefix = null
	): void {
		global $wpdb;

		$expected_blog_id = $expected_blog_id ?? get_current_blog_id();
		$expected_prefix = $expected_prefix ?? $wpdb->prefix;
		RestHookRecordingRestApi::$trace = [];
		$response = $this->dispatch_case( $server, $case );

		self::assertSame( 200, $response->get_status(), $case['method'] . ' ' . $case['route'] );
		self::assertSame(
			[
				'client_object_id' => spl_object_id( $delegate->getClient() ),
				'delegate_object_id' => spl_object_id( $delegate ),
				'handler' => $case['handler'],
				'method' => $case['method'],
			],
			$response->get_data()
		);
		self::assertSame(
			[
				[
					'blog_id' => $expected_blog_id,
					'prefix' => $expected_prefix,
					'delegate_object_id' => spl_object_id( $delegate ),
					'stage' => 'permission',
					'handler' => $case['handler'],
				],
				[
					'blog_id' => $expected_blog_id,
					'prefix' => $expected_prefix,
					'delegate_object_id' => spl_object_id( $delegate ),
					'stage' => 'handler',
					'handler' => $case['handler'],
				],
			],
			RestHookRecordingRestApi::$trace
		);
	}

	private function dispatch_case( WP_REST_Server $server, array $case ): WP_REST_Response
	{
		$request = new WP_REST_Request( $case['method'], $case['route'] );
		$request->set_body_params( $case['payload'] );

		return $server->dispatch( $request );
	}

	private function assert_native_rest_error(
		string $expected_code,
		int $expected_status,
		WP_REST_Response $response
	): void {
		$data = $response->get_data();

		self::assertSame( $expected_status, $response->get_status() );
		self::assertSame( $expected_code, $data['code'] ?? null );
		self::assertSame( $expected_status, $data['data']['status'] ?? null );
	}

	private function capture_client_registration_failure( callable $operation ): ClientRegisterFail
	{
		try {
			$operation();
		} catch ( ClientRegisterFail $exception ) {
			return $exception;
		}

		self::fail( 'Expected ClientRegisterFail.' );
	}
}
