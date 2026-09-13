<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use Error;
use iTRON\wpConnections\Exceptions\ConnectionEndpointInvalid;
use iTRON\wpConnections\Exceptions\ConnectionEndpointNotFound;
use iTRON\wpConnections\Exceptions\ConnectionEndpointResolverFail;
use iTRON\wpConnections\Exceptions\ConnectionEndpointTypeMismatch;
use iTRON\wpConnections\Exceptions\ConnectionEndpointTypeUnsupported;
use iTRON\wpConnections\Exceptions\ConnectionRelationMismatch;
use iTRON\wpConnections\Exceptions\StorageFailure;
use iTRON\wpConnections\Query\Connection;
use iTRON\wpConnections\RestResponse\CollectionItem;
use RuntimeException;
use Throwable;

class RestConnectionContractTest extends WPConnectionsTestCase
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

	public function test_full_connection_crud_preserves_v1_payloads_and_state(): void
	{
		$relation_route = $this->relation_route( RELATION_0_NAME );
		$create_response = $this->dispatch_rest_request(
			'POST',
			$relation_route,
			[
				'from'  => $this->page_ids[0],
				'to'    => $this->post_ids[0],
				'order' => 7,
				'meta'  => [ [ 'key' => 'source', 'value' => 'REST' ] ],
			]
		);

		self::assertSame( 200, $create_response->get_status() );
		$created = $create_response->get_data();
		self::assertIsInt( $created['id'] ?? null );
		self::assertGreaterThan( 0, $created['id'] );
		self::assertSame(
			[
				'id'       => $created['id'],
				'title'    => null,
				'relation' => RELATION_0_NAME,
				'from'     => $this->page_ids[0],
				'to'       => $this->post_ids[0],
				'order'    => 7,
				'meta'     => [ 'source' => [ 'REST' ] ],
			],
			$created
		);

		$connection_route = $relation_route . '/' . $created['id'];
		$get_response = $this->dispatch_rest_request( 'GET', $connection_route );
		self::assertSame( 200, $get_response->get_status() );
		self::assertSame( $created, $get_response->get_data() );

		$update_response = $this->dispatch_rest_request(
			'PATCH',
			$connection_route,
			[ 'title' => 'Updated through REST' ]
		);
		self::assertSame( 200, $update_response->get_status() );
		self::assertSame( [ 'updated' => true ], $update_response->get_data() );

		$updated = $this->dispatch_rest_request( 'GET', $connection_route )->get_data();
		self::assertSame( 'Updated through REST', $updated['title'] );
		self::assertSame( [ 'source' => [ 'REST' ] ], $updated['meta'] );

		$list_response = $this->dispatch_rest_request( 'GET', $relation_route );
		self::assertSame( 200, $list_response->get_status() );
		self::assertCount( 1, $list_response->get_data() );
		self::assertInstanceOf( CollectionItem::class, $list_response->get_data()[0] );
		self::assertSame( $updated, $list_response->get_data()[0]->get_data() );
		self::assertSame(
			$this->rest_url( $connection_route ),
			$list_response->get_data()[0]->get_links()['self'][0]['href']
		);
		$wire_list = json_decode(
			wp_json_encode( $this->rest_server->response_to_data( $list_response, false ) ),
			true
		);
		self::assertSame(
			[
				[
					'data'  => $updated,
					'links' => [
						'self' => [
							[
								'href'       => $this->rest_url( $connection_route ),
								'attributes' => [],
							],
						],
					],
				],
			],
			$wire_list
		);
		self::assertArrayNotHasKey( '_links', $wire_list[0] );

		$delete_response = $this->dispatch_rest_request( 'DELETE', $connection_route );
		self::assertSame( 200, $delete_response->get_status() );
		self::assertSame( [ 'deleted' => true ], $delete_response->get_data() );
		$this->assert_domain_error(
			2,
			404,
			'Connection not found.',
			$this->dispatch_rest_request( 'GET', $connection_route )
		);
		self::assertTrue( $this->client->getRelation( RELATION_0_NAME )->findConnections()->isEmpty() );
	}

	public function test_client_collection_items_keep_relation_data_and_self_link(): void
	{
		$response = $this->dispatch_rest_request( 'GET', $this->get_rest_route() );
		$items = $response->get_data();

		self::assertSame( 200, $response->get_status() );
		self::assertCount( 2, $items );
		self::assertInstanceOf( CollectionItem::class, $items[0] );
		self::assertSame( RELATION_0_NAME, $items[0]->get_data()['name'] );
		self::assertSame(
			$this->rest_url( $this->relation_route( RELATION_0_NAME ) ),
			$items[0]->get_links()['self'][0]['href']
		);
		$wire_items = json_decode(
			wp_json_encode( $this->rest_server->response_to_data( $response, false ) ),
			true
		);
		self::assertSame(
			[
				'name'         => RELATION_0_NAME,
				'from'         => 'page',
				'to'           => 'post',
				'type'         => 'both',
				'cardinality'  => 'm-m',
				'duplicatable' => false,
				'closurable'   => false,
			],
			$wire_items[0]['data']
		);
		self::assertSame(
			[
				'self' => [
					[
						'href'       => $this->rest_url( $this->relation_route( RELATION_0_NAME ) ),
						'attributes' => [],
					],
				],
			],
			$wire_items[0]['links']
		);
		self::assertArrayNotHasKey( '_links', $wire_items[0] );
	}

	public function test_path_selectors_override_body_values_for_connection_crud(): void
	{
		$first = $this->create_connection( RELATION_0_NAME, $this->post_ids[0] );
		$second = $this->create_connection( RELATION_0_NAME, $this->post_ids[1] );

		$single_response = $this->dispatch_rest_request(
			'GET',
			$this->connection_route( RELATION_0_NAME, $first->id ),
			[ 'connectionID' => $second->id ]
		);
		self::assertSame( $first->id, $single_response->get_data()['id'] ?? null );

		$list_request = new \WP_REST_Request(
			'GET',
			$this->relation_route( RELATION_0_NAME )
		);
		$list_request->set_query_params( [ 'relation' => RELATION_1_NAME ] );
		$list_response = $this->rest_server->dispatch( $list_request );
		self::assertCount( 2, $list_response->get_data() );
		foreach ( $list_response->get_data() as $item ) {
			self::assertSame( RELATION_0_NAME, $item->get_data()['relation'] ?? null );
		}
		$third_post = self::factory()->post->create(
			[
				'post_title'  => 'Third REST post',
				'post_status' => 'publish',
				'post_type'   => 'post',
			]
		);

		$create_response = $this->dispatch_rest_request(
			'POST',
			$this->relation_route( RELATION_0_NAME ),
			[
				'relation' => RELATION_1_NAME,
				'from'     => $this->page_ids[0],
				'to'       => $third_post,
			]
		);
		self::assertSame( RELATION_0_NAME, $create_response->get_data()['relation'] ?? null );

		$delete_response = $this->dispatch_rest_request(
			'DELETE',
			$this->connection_route( RELATION_0_NAME, $first->id ),
			[
				'relation'     => RELATION_1_NAME,
				'connectionID' => $second->id,
			]
		);
		self::assertSame( [ 'deleted' => true ], $delete_response->get_data() );
		self::assertFalse( $this->client->getRelation( RELATION_0_NAME )->hasConnectionID( $first->id ) );
		self::assertTrue( $this->client->getRelation( RELATION_0_NAME )->hasConnectionID( $second->id ) );
	}

	/**
	 * @dataProvider unknown_relation_route_provider
	 */
	public function test_maps_unknown_relation_to_numeric_domain_404(
		string $method,
		bool $connection_route,
		array $payload
	): void
	{
		$response = $this->dispatch_rest_request(
			$method,
			$connection_route
				? $this->connection_route( 'unknown-relation', 999999 )
				: $this->relation_route( 'unknown-relation' ),
			$payload
		);

		$this->assert_domain_error(
			1,
			404,
			'Relation not found: unknown-relation',
			$response
		);
	}

	public function unknown_relation_route_provider(): array
	{
		return [
			'relation read'     => [ 'GET', false, [] ],
			'relation create'   => [ 'POST', false, [ 'from' => 1, 'to' => 2 ] ],
			'connection read'   => [ 'GET', true, [] ],
			'connection patch'  => [ 'PATCH', true, [ 'title' => 'ignored' ] ],
			'connection post'   => [ 'POST', true, [ 'from' => 1, 'to' => 2 ] ],
			'connection put'    => [ 'PUT', true, [ 'from' => 1, 'to' => 2 ] ],
			'connection delete' => [ 'DELETE', true, [] ],
		];
	}

	/**
	 * @dataProvider missing_connection_method_provider
	 */
	public function test_maps_missing_connection_to_numeric_domain_404(
		string $method,
		bool $require_endpoints
	): void
	{
		$response = $this->dispatch_rest_request(
			$method,
			$this->connection_route( RELATION_0_NAME, 999999 ),
			$require_endpoints
				? [ 'from' => $this->page_ids[0], 'to' => $this->post_ids[0] ]
				: []
		);

		$this->assert_domain_error( 2, 404, 'Connection not found.', $response );
	}

	public function missing_connection_method_provider(): array
	{
		return [
			'get'    => [ 'GET', false ],
			'patch'  => [ 'PATCH', false ],
			'post'   => [ 'POST', true ],
			'put'    => [ 'PUT', true ],
			'delete' => [ 'DELETE', false ],
		];
	}

	public function test_relation_mismatch_update_maps_to_400_without_mutation(): void
	{
		$connection = $this->create_connection( RELATION_0_NAME, $this->post_ids[0] );

		$response = $this->dispatch_rest_request(
			'PATCH',
			$this->connection_route( RELATION_1_NAME, $connection->id ),
			[ 'title' => 'must not persist' ]
		);

		$this->assert_domain_error(
			310,
			400,
			'Connection relation identity mismatch: expected ' . RELATION_0_NAME
				. ', got ' . RELATION_1_NAME . '.',
			$response
		);
		$persisted = $this->client->getRelation( RELATION_0_NAME )->findConnections()->first();
		self::assertSame( $connection->id, $persisted->id );
		self::assertNull( $persisted->title );
	}

	public function test_maps_domain_validation_to_numeric_domain_400_without_mutation(): void
	{
		$response = $this->dispatch_rest_request(
			'POST',
			$this->relation_route( RELATION_0_NAME ),
			[
				'from' => 0,
				'to'   => $this->post_ids[0],
			]
		);

		$this->assert_domain_error( 4, 400, 'Missing required fields: from ', $response );
		self::assertTrue( $this->client->getRelation( RELATION_0_NAME )->findConnections()->isEmpty() );
	}

	public function test_maps_known_code_300_validation_to_domain_400(): void
	{
		$response = $this->dispatch_rest_request(
			'POST',
			$this->relation_route( RELATION_0_NAME ),
			[
				'from'  => $this->page_ids[0],
				'to'    => $this->post_ids[0],
				'order' => -1,
			]
		);

		$this->assert_domain_error(
			300,
			400,
			'Connection order must be a non-negative integer.',
			$response
		);
		self::assertTrue( $this->client->getRelation( RELATION_0_NAME )->findConnections()->isEmpty() );
	}

	/**
	 * @dataProvider classified_domain_failure_provider
	 */
	public function test_maps_classified_domain_failure_and_emits_no_success_hook(
		string $failure_class,
		array $arguments,
		int $expected_status
	): void {
		/** @var Throwable $failure */
		$failure = new $failure_class( ...$arguments );
		$created_calls = 0;
		$throw_failure = static function () use ( $failure ): void {
			throw $failure;
		};
		$record_created = static function () use ( &$created_calls ): void {
			$created_calls++;
		};
		add_action( 'wpConnections/relation/creating', $throw_failure );
		add_action( 'wpConnections/relation/created', $record_created );

		try {
			$response = $this->dispatch_rest_request(
				'POST',
				$this->relation_route( RELATION_0_NAME ),
				[
					'from' => $this->page_ids[0],
					'to'   => $this->post_ids[0],
				]
			);
		} finally {
			remove_action( 'wpConnections/relation/created', $record_created );
			remove_action( 'wpConnections/relation/creating', $throw_failure );
		}

		$this->assert_domain_error(
			$failure->getCode(),
			$expected_status,
			$failure->getMessage(),
			$response
		);
		self::assertSame( 0, $created_calls );
		self::assertTrue( $this->client->getRelation( RELATION_0_NAME )->findConnections()->isEmpty() );
	}

	public function classified_domain_failure_provider(): array
	{
		return [
			'closure invariant' => [
				\iTRON\wpConnections\Exceptions\ConnectionWrongData::class,
				[ 'Closurable not allowed by relation settings.', 301 ],
				409,
			],
			'cardinality invariant' => [
				\iTRON\wpConnections\Exceptions\ConnectionWrongData::class,
				[ 'Cardinality violation.', 302 ],
				409,
			],
			'duplicate invariant' => [
				\iTRON\wpConnections\Exceptions\ConnectionWrongData::class,
				[ 'Duplicatable violation.', 303 ],
				409,
			],
			'empty aggregate ID' => [
				\iTRON\wpConnections\Exceptions\ConnectionWrongData::class,
				[ 'Cannot update uninitialized connection', 304 ],
				400,
			],
			'invalid endpoint' => [ ConnectionEndpointInvalid::class, [ 'from', -1 ], 400 ],
			'missing endpoint' => [ ConnectionEndpointNotFound::class, [ 'from', 999999 ], 404 ],
			'endpoint type mismatch' => [
				ConnectionEndpointTypeMismatch::class,
				[ 'from', 'page', 'post' ],
				400,
			],
			'unsupported endpoint type' => [
				ConnectionEndpointTypeUnsupported::class,
				[ 'from', 'custom' ],
				400,
			],
			'endpoint resolver failure' => [
				ConnectionEndpointResolverFail::class,
				[ 'from', 'custom', 42, new RuntimeException( 'resolver detail' ) ],
				400,
			],
			'relation identity mismatch' => [
				ConnectionRelationMismatch::class,
				[ 'expected', 'actual' ],
				400,
			],
		];
	}

	public function test_maps_real_storage_failure_to_exact_generic_500_and_logs_cause(): void
	{
		global $wpdb;

		$query_fragment = 'INSERT INTO `' . $wpdb->prefix
			. $this->client->getStorage()->get_connections_table() . '`';
		$intercepted = false;
		$query_filter = static function ( string $query ) use ( $query_fragment, &$intercepted ): string {
			if ( ! $intercepted && false !== strpos( $query, $query_fragment ) ) {
				$intercepted = true;
				return 'SELECT * FROM `wpconnections_batch16_secret_table`';
			}

			return $query;
		};
		$logs = [];
		$logger = static function ( array $record, string $level ) use ( &$logs ): void {
			$logs[] = [ 'record' => $record, 'level' => $level ];
		};
		$created_calls = 0;
		$created = static function () use ( &$created_calls ): void {
			$created_calls++;
		};
		$suppress = $wpdb->suppress_errors();
		add_filter( 'query', $query_filter );
		add_action( 'logger', $logger, 10, 2 );
		add_action( 'wpConnections/relation/created', $created );

		try {
			$response = $this->dispatch_rest_request(
				'POST',
				$this->relation_route( RELATION_0_NAME ),
				[
					'from' => $this->page_ids[0],
					'to'   => $this->post_ids[0],
				]
			);
		} finally {
			remove_action( 'wpConnections/relation/created', $created );
			remove_action( 'logger', $logger, 10 );
			remove_filter( 'query', $query_filter );
			$wpdb->suppress_errors( $suppress );
		}

		self::assertTrue( $intercepted );
		$this->assert_internal_error( $response );
		self::assertStringNotContainsString(
			'wpconnections_batch16_secret_table',
			wp_json_encode( $response->get_data() )
		);
		self::assertSame( 0, $created_calls );
		$error_logs = array_values(
			array_filter(
				$logs,
				static function ( array $log ): bool {
					return 'wpConnections REST request failed.' === ( $log['record'][0] ?? null );
				}
			)
		);
		self::assertCount( 1, $error_logs );
		self::assertSame( 'error', $error_logs[0]['level'] );
		self::assertInstanceOf(
			Throwable::class,
			$error_logs[0]['record'][1]['exception'] ?? null
		);
		self::assertTrue( $this->client->getRelation( RELATION_0_NAME )->findConnections()->isEmpty() );
	}

	/**
	 * @dataProvider connection_storage_failure_provider
	 */
	public function test_maps_connection_route_storage_failure_without_mutation(
		string $operation
	): void {
		global $wpdb;

		$connection = $this->create_connection( RELATION_0_NAME, $this->post_ids[0] );
		$table = $wpdb->prefix . $this->client->getStorage()->get_connections_table();
		$connection_route = $this->connection_route( RELATION_0_NAME, $connection->id );

		switch ( $operation ) {
			case 'relation read':
				$query_fragment = "SELECT c.*, m.* FROM {$table}";
				$request = function () {
					return $this->dispatch_rest_request(
						'GET',
						$this->relation_route( RELATION_0_NAME )
					);
				};
				break;
			case 'single read':
				$query_fragment = "SELECT c.*, m.* FROM {$table}";
				$request = function () use ( $connection_route ) {
					return $this->dispatch_rest_request( 'GET', $connection_route );
				};
				break;
			case 'update':
				$query_fragment = "UPDATE `{$table}` SET";
				$request = function () use ( $connection_route ) {
					return $this->dispatch_rest_request(
						'PATCH',
						$connection_route,
						[ 'title' => 'must roll back' ]
					);
				};
				break;
			case 'delete':
				$query_fragment = "DELETE FROM {$table} WHERE";
				$request = function () use ( $connection_route ) {
					return $this->dispatch_rest_request( 'DELETE', $connection_route );
				};
				break;
			default:
				self::fail( "Unknown storage failure operation: {$operation}" );
		}

		$intercepted = false;
		$query_filter = static function ( string $query ) use ( $query_fragment, &$intercepted ): string {
			if ( ! $intercepted && false !== strpos( $query, $query_fragment ) ) {
				$intercepted = true;
				return 'SELECT * FROM `wpconnections_batch16_secret_route_failure`';
			}

			return $query;
		};
		$delete_success_calls = 0;
		$delete_success = static function () use ( &$delete_success_calls ): void {
			$delete_success_calls++;
		};
		$suppress = $wpdb->suppress_errors();
		add_filter( 'query', $query_filter );
		add_action( 'wpConnections/storage/deletedSpecificConnections', $delete_success );

		try {
			$response = $request();
		} finally {
			remove_action( 'wpConnections/storage/deletedSpecificConnections', $delete_success );
			remove_filter( 'query', $query_filter );
			$wpdb->suppress_errors( $suppress );
		}

		self::assertTrue( $intercepted, "Expected {$operation} database interception." );
		$this->assert_internal_error( $response );
		self::assertStringNotContainsString(
			'wpconnections_batch16_secret_route_failure',
			wp_json_encode( $response->get_data() )
		);
		self::assertSame( 0, $delete_success_calls );
		$persisted = $this->client->getRelation( RELATION_0_NAME )->findConnections();
		self::assertCount( 1, $persisted );
		self::assertNull( $persisted->first()->title );
	}

	public function connection_storage_failure_provider(): array
	{
		return [
			'relation read' => [ 'relation read' ],
			'single read'   => [ 'single read' ],
			'update'        => [ 'update' ],
			'delete'        => [ 'delete' ],
		];
	}

	public function test_schema_code_300_cause_is_internal_and_not_public_validation(): void
	{
		global $wpdb;

		$table = $wpdb->prefix . $this->client->getStorage()->get_connections_table();
		self::assertNotFalse( $wpdb->query( "ALTER TABLE `{$table}` ENGINE=MyISAM" ) );
		$error_logs = [];
		$logger = static function ( array $record, string $level ) use ( &$error_logs ): void {
			if ( 'wpConnections REST request failed.' === ( $record[0] ?? null ) ) {
				$error_logs[] = [ 'record' => $record, 'level' => $level ];
			}
		};
		add_action( 'logger', $logger, 10, 2 );

		try {
			$response = $this->dispatch_rest_request(
				'POST',
				$this->relation_route( RELATION_0_NAME ),
				[
					'from' => $this->page_ids[0],
					'to'   => $this->post_ids[0],
				]
			);
		} finally {
			remove_action( 'logger', $logger, 10 );
		}

		$this->assert_internal_error( $response );
		self::assertStringNotContainsString( $table, wp_json_encode( $response->get_data() ) );
		self::assertCount( 1, $error_logs );
		self::assertSame( 'error', $error_logs[0]['level'] );
		$failure = $error_logs[0]['record'][1]['exception'] ?? null;
		self::assertInstanceOf( \iTRON\wpConnections\Exceptions\ConnectionWrongData::class, $failure );
		self::assertSame( 300, $failure->getCode() );
		self::assertInstanceOf( StorageFailure::class, $failure->getPrevious() );
		self::assertSame(
			0,
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" )
		);
	}

	/**
	 * @dataProvider unknown_throwable_provider
	 */
	public function test_maps_unknown_throwable_to_exact_generic_500( Throwable $failure ): void
	{
		$throw_failure = static function () use ( $failure ): void {
			throw $failure;
		};
		add_action( 'wpConnections/relation/creating', $throw_failure );

		try {
			$response = $this->dispatch_rest_request(
				'POST',
				$this->relation_route( RELATION_0_NAME ),
				[
					'from' => $this->page_ids[0],
					'to'   => $this->post_ids[0],
				]
			);
		} finally {
			remove_action( 'wpConnections/relation/creating', $throw_failure );
		}

		$this->assert_internal_error( $response );
		self::assertStringNotContainsString(
			$failure->getMessage(),
			wp_json_encode( $response->get_data() )
		);
	}

	public function unknown_throwable_provider(): array
	{
		return [
			'exception' => [ new RuntimeException( 'private runtime detail' ) ],
			'error'     => [ new Error( 'private native error detail' ) ],
		];
	}

	public function test_logging_failure_does_not_change_generic_500(): void
	{
		$original = new Error( 'original private detail' );
		$throw_original = static function () use ( $original ): void {
			throw $original;
		};
		$throw_logger = static function ( array $record ): void {
			if ( 'wpConnections REST request failed.' === ( $record[0] ?? null ) ) {
				throw new RuntimeException( 'logger transport failed' );
			}
		};
		add_action( 'wpConnections/relation/creating', $throw_original );
		add_action( 'logger', $throw_logger );

		try {
			$response = $this->dispatch_rest_request(
				'POST',
				$this->relation_route( RELATION_0_NAME ),
				[
					'from' => $this->page_ids[0],
					'to'   => $this->post_ids[0],
				]
			);
		} finally {
			remove_action( 'logger', $throw_logger );
			remove_action( 'wpConnections/relation/creating', $throw_original );
		}

		$this->assert_internal_error( $response );
	}

	public function test_create_post_commit_hook_failure_keeps_durable_success(): void
	{
		$failure = new RuntimeException( 'private create success-hook failure' );
		$success_calls = 0;
		$throw_after_commit = static function () use ( &$success_calls, $failure ): void {
			$success_calls++;
			throw $failure;
		};
		$logs = [];
		$logger = static function ( array $record, string $level ) use ( &$logs ): void {
			if ( 'wpConnections REST request failed.' === ( $record[0] ?? null ) ) {
				$logs[] = [ 'record' => $record, 'level' => $level ];
			}
		};
		add_action( 'wpConnections/relation/created', $throw_after_commit );
		add_action( 'logger', $logger, 10, 2 );

		try {
			$response = $this->dispatch_rest_request(
				'POST',
				$this->relation_route( RELATION_0_NAME ),
				[
					'from' => $this->page_ids[0],
					'to'   => $this->post_ids[0],
					'meta' => [ [ 'key' => 'commit', 'value' => 'durable' ] ],
				]
			);
		} finally {
			remove_action( 'logger', $logger, 10 );
			remove_action( 'wpConnections/relation/created', $throw_after_commit );
		}

		$this->assert_internal_error( $response );
		self::assertStringNotContainsString( $failure->getMessage(), wp_json_encode( $response->get_data() ) );
		self::assertSame( 1, $success_calls );
		self::assertCount( 1, $logs );
		self::assertSame( 'error', $logs[0]['level'] );
		self::assertSame( $failure, $logs[0]['record'][1]['exception'] ?? null );
		$persisted = $this->client->getRelation( RELATION_0_NAME )->findConnections();
		self::assertCount( 1, $persisted );
		self::assertSame( [ 'commit' => [ 'durable' ] ], $persisted->first()->meta->toArray() );
	}

	public function test_create_post_commit_domain_failure_keeps_numeric_mapping_and_durable_success(): void
	{
		$failure = new \iTRON\wpConnections\Exceptions\ConnectionWrongData(
			'Consumer success hook reported a duplicate conflict.',
			303
		);
		$success_calls = 0;
		$throw_after_commit = static function () use ( &$success_calls, $failure ): void {
			$success_calls++;
			throw $failure;
		};
		$logs = [];
		$logger = static function ( array $record ) use ( &$logs ): void {
			if ( 'wpConnections REST request failed.' === ( $record[0] ?? null ) ) {
				$logs[] = $record;
			}
		};
		add_action( 'wpConnections/relation/created', $throw_after_commit );
		add_action( 'logger', $logger );

		try {
			$response = $this->dispatch_rest_request(
				'POST',
				$this->relation_route( RELATION_0_NAME ),
				[
					'from' => $this->page_ids[0],
					'to'   => $this->post_ids[0],
					'meta' => [ [ 'key' => 'commit', 'value' => 'classified' ] ],
				]
			);
		} finally {
			remove_action( 'logger', $logger );
			remove_action( 'wpConnections/relation/created', $throw_after_commit );
		}

		$this->assert_domain_error( 303, 409, $failure->getMessage(), $response );
		self::assertSame( 1, $success_calls );
		self::assertSame( [], $logs );
		$persisted = $this->client->getRelation( RELATION_0_NAME )->findConnections();
		self::assertCount( 1, $persisted );
		self::assertSame( [ 'commit' => [ 'classified' ] ], $persisted->first()->meta->toArray() );
	}

	public function test_delete_post_commit_hook_failure_keeps_durable_success_when_logger_fails(): void
	{
		global $wpdb;

		$query = new Connection( $this->page_ids[0], $this->post_ids[0] );
		$query->meta->fromArray( [ [ 'key' => 'delete', 'value' => 'with parent' ] ] );
		$connection = $this->client->getRelation( RELATION_0_NAME )->createConnection( $query );
		$failure = new RuntimeException( 'private delete success-hook failure' );
		$success_calls = 0;
		$throw_after_commit = static function () use ( &$success_calls, $failure ): void {
			$success_calls++;
			throw $failure;
		};
		$logs = [];
		$throwing_logger = static function ( array $record, string $level ) use ( &$logs ): void {
			if ( 'wpConnections REST request failed.' !== ( $record[0] ?? null ) ) {
				return;
			}

			$logs[] = [ 'record' => $record, 'level' => $level ];
			throw new RuntimeException( 'delete logger transport failed' );
		};
		add_action( 'wpConnections/storage/deletedSpecificConnections', $throw_after_commit );
		add_action( 'logger', $throwing_logger, 10, 2 );

		try {
			$response = $this->dispatch_rest_request(
				'DELETE',
				$this->connection_route( RELATION_0_NAME, $connection->id )
			);
		} finally {
			remove_action( 'logger', $throwing_logger, 10 );
			remove_action( 'wpConnections/storage/deletedSpecificConnections', $throw_after_commit );
		}

		$this->assert_internal_error( $response );
		self::assertStringNotContainsString( $failure->getMessage(), wp_json_encode( $response->get_data() ) );
		self::assertSame( 1, $success_calls );
		self::assertCount( 1, $logs );
		self::assertSame( 'error', $logs[0]['level'] );
		self::assertSame( $failure, $logs[0]['record'][1]['exception'] ?? null );
		self::assertTrue( $this->client->getRelation( RELATION_0_NAME )->findConnections()->isEmpty() );
		$meta_table = $wpdb->prefix . $this->client->getStorage()->get_meta_table();
		self::assertSame(
			0,
			(int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `{$meta_table}` WHERE `connection_id` = %d",
					$connection->id
				)
			)
		);
	}

	private function create_connection( string $relation_name, int $to ): \iTRON\wpConnections\Connection
	{
		return $this->client->getRelation( $relation_name )->createConnection(
			new Connection( $this->page_ids[0], $to )
		);
	}

	private function relation_route( string $relation_name ): string
	{
		return $this->get_rest_route( '/relation/' . $relation_name );
	}

	private function connection_route( string $relation_name, int $connection_id ): string
	{
		return $this->relation_route( $relation_name ) . '/' . $connection_id;
	}

	private function rest_url( string $route ): string
	{
		return rest_url( ltrim( $route, '/' ) );
	}

	private function assert_domain_error(
		int $expected_code,
		int $expected_status,
		string $expected_message,
		\WP_REST_Response $response
	): void {
		self::assertSame( $expected_status, $response->get_status() );
		self::assertSame(
			[
				'code'    => $expected_code,
				'message' => $expected_message,
				'data'    => [
					'status'      => $expected_status,
					'domain_code' => $expected_code,
				],
			],
			$response->get_data()
		);
	}

	private function assert_internal_error( \WP_REST_Response $response ): void
	{
		self::assertSame( 500, $response->get_status() );
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
