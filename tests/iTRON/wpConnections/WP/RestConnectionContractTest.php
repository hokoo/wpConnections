<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use Error;
use iTRON\wpConnections\Exceptions\ConnectionEndpointInvalid;
use iTRON\wpConnections\Exceptions\ConnectionEndpointNotFound;
use iTRON\wpConnections\Exceptions\ConnectionEndpointResolverFail;
use iTRON\wpConnections\Exceptions\ConnectionEndpointTypeMismatch;
use iTRON\wpConnections\Exceptions\ConnectionEndpointTypeUnsupported;
use iTRON\wpConnections\Exceptions\ConnectionRelationMismatch;
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
		$wire_list = json_decode( wp_json_encode( $list_response->get_data() ), true );
		self::assertSame( $updated, $wire_list[0]['data'] );
		self::assertSame(
			$this->rest_url( $connection_route ),
			$wire_list[0]['links']['self'][0]['href']
		);
		self::assertSame( [], $wire_list[0]['links']['self'][0]['attributes'] );
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
		$wire_items = json_decode( wp_json_encode( $items ), true );
		self::assertSame( RELATION_0_NAME, $wire_items[0]['data']['name'] );
		self::assertSame( 'both', $wire_items[0]['data']['type'] );
		self::assertSame(
			$this->rest_url( $this->relation_route( RELATION_0_NAME ) ),
			$wire_items[0]['links']['self'][0]['href']
		);
		self::assertSame( [], $wire_items[0]['links']['self'][0]['attributes'] );
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

		$list_response = $this->dispatch_rest_request(
			'GET',
			$this->relation_route( RELATION_0_NAME ),
			[ 'relation' => RELATION_1_NAME ]
		);
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

	public function test_maps_unknown_relation_to_numeric_domain_404(): void
	{
		$response = $this->dispatch_rest_request(
			'GET',
			$this->relation_route( 'unknown-relation' )
		);

		$this->assert_domain_error(
			1,
			404,
			'Relation not found: unknown-relation',
			$response
		);
	}

	/**
	 * @dataProvider missing_connection_method_provider
	 */
	public function test_maps_missing_connection_to_numeric_domain_404( string $method ): void
	{
		$response = $this->dispatch_rest_request(
			$method,
			$this->connection_route( RELATION_0_NAME, 999999 )
		);

		$this->assert_domain_error( 2, 404, 'Connection not found.', $response );
	}

	public function missing_connection_method_provider(): array
	{
		return [
			'get'    => [ 'GET' ],
			'patch'  => [ 'PATCH' ],
			'delete' => [ 'DELETE' ],
		];
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
		self::assertCount( 1, $logs );
		self::assertSame( 'error', $logs[0]['level'] );
		self::assertSame( 'wpConnections REST request failed.', $logs[0]['record'][0] );
		self::assertInstanceOf( Throwable::class, $logs[0]['record'][1]['exception'] ?? null );
		self::assertTrue( $this->client->getRelation( RELATION_0_NAME )->findConnections()->isEmpty() );
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
