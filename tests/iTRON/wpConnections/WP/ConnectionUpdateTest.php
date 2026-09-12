<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Connection as StoredConnection;
use iTRON\wpConnections\Exceptions\ConnectionWrongData;
use iTRON\wpConnections\Meta;
use iTRON\wpConnections\Query\Connection as ConnectionQuery;
use iTRON\wpConnections\Query\Meta as QueryMeta;

class ConnectionUpdateTest extends WPConnectionsTestCase
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

	public function test_patch_updates_order_to_zero_without_title_or_endpoints_and_preserves_state(): void
	{
		$connection = $this->create_connection_with_metadata();

		$response = $this->dispatch_rest_request(
			'PATCH',
			$this->connection_route( $connection ),
			[
				'order' => 0,
				'meta'  => [ [ 'key' => 'injected', 'value' => 'ignored' ] ],
			]
		);

		self::assertSame( 200, $response->get_status() );
		self::assertSame( [ 'updated' => true ], $response->get_data() );

		$persisted = $this->find_connection( $connection->id );
		self::assertSame( $this->page_ids[0], $persisted->from );
		self::assertSame( $this->post_ids[0], $persisted->to );
		self::assertSame( 'Original title', $persisted->title );
		self::assertSame( 0, $persisted->order );
		self::assertSame( [ 'marker' => [ 'preserved' ] ], $persisted->meta->toArray() );
	}

	public function test_empty_patch_is_a_valid_noop(): void
	{
		$connection = $this->create_connection_with_metadata();

		$response = $this->dispatch_rest_request( 'PATCH', $this->connection_route( $connection ) );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( [ 'updated' => false ], $response->get_data() );
		$this->assert_original_connection_state( $this->find_connection( $connection->id ) );
	}

	/**
	 * @dataProvider replacement_method_provider
	 */
	public function test_replacement_methods_require_endpoints( string $method ): void
	{
		$connection = $this->create_connection_with_metadata();

		$response = $this->dispatch_rest_request( $method, $this->connection_route( $connection ) );

		$this->assert_rest_error_response( 'rest_missing_callback_param', 400, $response );
		self::assertSame( [ 'from', 'to' ], $response->get_data()['data']['params'] );
		$this->assert_original_connection_state( $this->find_connection( $connection->id ) );
	}

	/**
	 * @dataProvider replacement_method_provider
	 */
	public function test_replacement_methods_apply_title_and_order_defaults( string $method ): void
	{
		$connection = $this->create_connection_with_metadata();

		$response = $this->dispatch_rest_request(
			$method,
			$this->connection_route( $connection ),
			[
				'from' => $this->page_ids[0],
				'to'   => $this->post_ids[0],
			]
		);

		self::assertSame( 200, $response->get_status() );
		self::assertSame( [ 'updated' => true ], $response->get_data() );

		$persisted = $this->find_connection( $connection->id );
		self::assertNull( $persisted->title );
		self::assertSame( 0, $persisted->order );
		self::assertSame( [ 'marker' => [ 'preserved' ] ], $persisted->meta->toArray() );
	}

	/**
	 * @dataProvider patch_title_provider
	 */
	public function test_patch_distinguishes_omitted_null_and_empty_title( ?string $title ): void
	{
		$connection = $this->create_connection_with_metadata();

		$response = $this->dispatch_rest_request(
			'PATCH',
			$this->connection_route( $connection ),
			[ 'title' => $title ]
		);

		self::assertSame( 200, $response->get_status() );
		self::assertSame( [ 'updated' => true ], $response->get_data() );
		self::assertSame( $title, $this->find_connection( $connection->id )->title );
	}

	public function test_patch_uses_route_selectors_instead_of_body_values(): void
	{
		$connection = $this->create_connection_with_metadata();

		$response = $this->dispatch_rest_request(
			'PATCH',
			$this->connection_route( $connection ),
			[
				'connectionID' => 999999,
				'relation'     => RELATION_1_NAME,
				'title'        => 'Route-owned selectors',
			]
		);

		self::assertSame( 200, $response->get_status() );
		self::assertSame( [ 'updated' => true ], $response->get_data() );
		self::assertSame( 'Route-owned selectors', $this->find_connection( $connection->id )->title );
	}

	/**
	 * @dataProvider invalid_order_provider
	 *
	 * @param mixed $order
	 */
	public function test_patch_rejects_invalid_order_without_mutation( $order ): void
	{
		$connection = $this->create_connection_with_metadata();

		$response = $this->dispatch_rest_request(
			'PATCH',
			$this->connection_route( $connection ),
			[ 'order' => $order ]
		);

		$this->assert_rest_error_response( 'rest_invalid_param', 400, $response );
		$this->assert_original_connection_state( $this->find_connection( $connection->id ) );
	}

	public function test_relation_sparse_update_persists_zero_and_reports_noop(): void
	{
		$connection = $this->create_connection_with_metadata();
		$update = new ConnectionQuery();
		$update->set( 'id', $connection->id );
		$update->set( 'order', 0 );

		self::assertTrue( $this->client->getRelation( RELATION_0_NAME )->updateConnection( $update ) );
		self::assertFalse( $this->client->getRelation( RELATION_0_NAME )->updateConnection( $update ) );

		$persisted = $this->find_connection( $connection->id );
		self::assertSame( 0, $persisted->order );
		self::assertSame( 'Original title', $persisted->title );
		self::assertSame( [ 'marker' => [ 'preserved' ] ], $persisted->meta->toArray() );
	}

	public function test_create_materializes_order_default_and_preserves_null_title(): void
	{
		$query = new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] );

		$created = $this->client->getRelation( RELATION_0_NAME )->createConnection( $query );
		$persisted = $this->find_connection( $created->id );

		self::assertSame( 0, $created->order );
		self::assertNull( $created->title );
		self::assertSame( 0, $persisted->order );
		self::assertNull( $persisted->title );
	}

	public function test_create_preserves_duplicate_and_allowed_falsy_metadata(): void
	{
		$query = new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] );
		$query->meta->fromArray(
			[
				[ 'key' => 'duplicate', 'value' => 'first' ],
				[ 'key' => 'duplicate', 'value' => 'second' ],
				[ 'key' => 'integer-zero', 'value' => 0 ],
				[ 'key' => 'string-zero', 'value' => '0' ],
				[ 'key' => 'false', 'value' => false ],
				[ 'key' => 'empty-string', 'value' => '' ],
			]
		);

		$connection = $this->client->getRelation( RELATION_0_NAME )->createConnection( $query );
		$persisted = $this->find_connection( $connection->id );

		self::assertSame(
			[
				'duplicate'   => [ 'first', 'second' ],
				'integer-zero' => [ '0' ],
				'string-zero'  => [ '0' ],
				'false'        => [ '' ],
				'empty-string' => [ '' ],
			],
			$persisted->meta->toArray()
		);
	}

	public function test_aggregate_update_replaces_and_clears_metadata(): void
	{
		$connection = $this->create_connection_with_metadata();
		$connection->meta->clear();
		$connection->meta->fromArray(
			[
				[ 'key' => 'replacement', 'value' => 'first' ],
				[ 'key' => 'replacement', 'value' => 'second' ],
			]
		);

		$connection->update();
		self::assertSame(
			[ 'replacement' => [ 'first', 'second' ] ],
			$this->find_connection( $connection->id )->meta->toArray()
		);

		$connection->meta->clear();
		$connection->update();
		self::assertSame( [], $this->find_connection( $connection->id )->meta->toArray() );
	}

	/**
	 * @dataProvider invalid_metadata_provider
	 *
	 * @param mixed $value
	 */
	public function test_rejects_invalid_metadata_before_create_mutation( string $key, $value ): void
	{
		$query = new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] );
		$query->meta->add( new QueryMeta( $key, $value ) );

		try {
			$this->client->getRelation( RELATION_0_NAME )->createConnection( $query );
			self::fail( 'Expected invalid metadata to be rejected.' );
		} catch ( ConnectionWrongData $exception ) {
			self::assertSame( 300, $exception->getCode() );
		}

		self::assertTrue( $this->client->getRelation( RELATION_0_NAME )->findConnections()->isEmpty() );
	}

	/**
	 * @dataProvider invalid_metadata_provider
	 *
	 * @param mixed $value
	 */
	public function test_rejects_invalid_metadata_before_aggregate_mutation( string $key, $value ): void
	{
		$connection = $this->create_connection_with_metadata();
		$connection->title = 'Must not persist';
		$connection->meta->clear();
		$connection->meta->add( new Meta( $key, $value ) );

		try {
			$connection->update();
			self::fail( 'Expected invalid metadata to be rejected.' );
		} catch ( ConnectionWrongData $exception ) {
			self::assertSame( 300, $exception->getCode() );
		}

		$this->assert_original_connection_state( $this->find_connection( $connection->id ) );
	}

	/**
	 * @dataProvider invalid_domain_order_provider
	 */
	public function test_relation_update_rejects_invalid_order_without_mutation( ?int $order ): void
	{
		$connection = $this->create_connection_with_metadata();
		$update = new ConnectionQuery();
		$update->set( 'id', $connection->id );
		$update->set( 'order', $order );

		try {
			$this->client->getRelation( RELATION_0_NAME )->updateConnection( $update );
			self::fail( 'Expected invalid order to be rejected.' );
		} catch ( ConnectionWrongData $exception ) {
			self::assertSame( 300, $exception->getCode() );
		}

		$this->assert_original_connection_state( $this->find_connection( $connection->id ) );
	}

	public static function replacement_method_provider(): array
	{
		return [
			'legacy POST' => [ 'POST' ],
			'PUT'         => [ 'PUT' ],
		];
	}

	public static function patch_title_provider(): array
	{
		return [
			'explicit null'  => [ null ],
			'explicit empty' => [ '' ],
		];
	}

	public static function invalid_order_provider(): array
	{
		return [
			'negative'     => [ -1 ],
			'null'         => [ null ],
			'boolean'      => [ false ],
			'empty string' => [ '' ],
		];
	}

	public static function invalid_domain_order_provider(): array
	{
		return [
			'negative' => [ -1 ],
			'null'     => [ null ],
		];
	}

	public static function invalid_metadata_provider(): array
	{
		return [
			'empty key' => [ '', 'value' ],
			'null value' => [ 'invalid-null', null ],
		];
	}

	private function create_connection_with_metadata(): StoredConnection
	{
		$query = new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] );
		$query->set( 'title', 'Original title' );
		$query->set( 'order', 10 );
		$query->meta->add( new QueryMeta( 'marker', 'preserved' ) );

		return $this->client->getRelation( RELATION_0_NAME )->createConnection( $query );
	}

	private function find_connection( int $connection_id ): StoredConnection
	{
		$query = new ConnectionQuery();
		$query->set( 'id', $connection_id );

		return $this->client->getRelation( RELATION_0_NAME )->findConnections( $query )->first();
	}

	private function connection_route( StoredConnection $connection ): string
	{
		return $this->get_rest_route(
			'/relation/' . RELATION_0_NAME . '/' . $connection->id
		);
	}

	private function assert_original_connection_state( StoredConnection $connection ): void
	{
		self::assertSame( $this->page_ids[0], $connection->from );
		self::assertSame( $this->post_ids[0], $connection->to );
		self::assertSame( 'Original title', $connection->title );
		self::assertSame( 10, $connection->order );
		self::assertSame( [ 'marker' => [ 'preserved' ] ], $connection->meta->toArray() );
	}
}
