<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\ConnectionCollection;
use iTRON\wpConnections\Meta;
use iTRON\wpConnections\MetaCollection;
use iTRON\wpConnections\Query\Connection as ConnectionQuery;
use iTRON\wpConnections\Query\Relation as RelationQuery;

class WPStorageFindConnectionsTest extends WPConnectionsTestCase
{
	private array $prepare_warnings = [];

	public function set_up()
	{
		parent::set_up();
		add_action( 'doing_it_wrong_run', [ $this, 'record_prepare_warning' ], 10, 3 );
	}

	public function tear_down()
	{
		try {
			self::assertSame( [], $this->prepare_warnings, implode( "\n", $this->prepare_warnings ) );
		} finally {
			remove_action( 'doing_it_wrong_run', [ $this, 'record_prepare_warning' ], 10 );
			parent::tear_down();
		}
	}

	public function record_prepare_warning( string $function_name, string $message, int $error_level ): void
	{
		if ( 'wpdb::prepare' === $function_name ) {
			$this->prepare_warnings[] = $message . ' (level ' . $error_level . ')';
		}
	}

	/**
	 * @dataProvider both_endpoint_provider
	 */
	public function test_finds_connection_when_both_matches_either_endpoint( string $endpoint ): void
	{
		$connection = $this->client->getRelation( RELATION_0_NAME )->createConnection(
			new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] )
		);

		$query = new ConnectionQuery();
		$query->set( 'both', $connection->{$endpoint} );
		$matches = $this->find_connections( RELATION_0_NAME, $query );

		$this->assert_connection_ids( [ $connection->id ], $matches );
	}

	public function both_endpoint_provider(): array
	{
		return [
			'from endpoint' => [ 'from' ],
			'to endpoint'   => [ 'to' ],
		];
	}

	public function test_applies_relation_endpoint_and_combination_filters(): void
	{
		$other_page_id = $this->create_entity( 'page', 'Other page' );
		$from_to_first = $this->create_connection(
			RELATION_0_NAME,
			$this->page_ids[0],
			$this->post_ids[0],
			20
		);
		$from_to_second = $this->create_connection(
			RELATION_0_NAME,
			$this->page_ids[0],
			$this->post_ids[1],
			10
		);
		$other_from_to_first = $this->create_connection(
			RELATION_0_NAME,
			$other_page_id,
			$this->post_ids[0],
			5
		);

		$other_relation_name = 'query-matrix-other-relation';
		$this->register_relation( $other_relation_name );
		$other_relation_connection = $this->create_connection(
			$other_relation_name,
			$this->page_ids[0],
			$this->post_ids[0]
		);

		$query = new ConnectionQuery();
		$query->set( 'from', $this->page_ids[0] );
		$this->assert_connection_ids(
			[ $from_to_second->id, $from_to_first->id ],
			$this->find_connections( RELATION_0_NAME, $query )
		);

		$query = new ConnectionQuery();
		$query->set( 'to', $this->post_ids[0] );
		$this->assert_connection_ids(
			[ $other_from_to_first->id, $from_to_first->id ],
			$this->find_connections( RELATION_0_NAME, $query )
		);

		$query = new ConnectionQuery();
		$query->set( 'both', $this->page_ids[0] );
		$this->assert_connection_ids(
			[ $from_to_second->id, $from_to_first->id ],
			$this->find_connections( RELATION_0_NAME, $query )
		);

		$query = new ConnectionQuery();
		$query->set( 'both', $this->post_ids[0] );
		$this->assert_connection_ids(
			[ $other_from_to_first->id, $from_to_first->id ],
			$this->find_connections( RELATION_0_NAME, $query )
		);

		$query = new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] );
		$this->assert_connection_ids(
			[ $from_to_first->id ],
			$this->find_connections( RELATION_0_NAME, $query )
		);

		$query = new ConnectionQuery();
		$query->set( 'from', $this->page_ids[0] );
		$query->set( 'both', $this->post_ids[0] );
		$this->assert_connection_ids(
			[ $from_to_first->id ],
			$this->find_connections( RELATION_0_NAME, $query )
		);

		$query = new ConnectionQuery();
		$query->set( 'to', $this->post_ids[0] );
		$query->set( 'both', $other_page_id );
		$this->assert_connection_ids(
			[ $other_from_to_first->id ],
			$this->find_connections( RELATION_0_NAME, $query )
		);

		$query = new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] );
		$query->set( 'both', $this->post_ids[0] );
		$this->assert_connection_ids(
			[ $from_to_first->id ],
			$this->find_connections( RELATION_0_NAME, $query )
		);

		$query->set( 'both', $this->post_ids[1] );
		self::assertTrue( $this->find_connections( RELATION_0_NAME, $query )->isEmpty() );

		$relation_query = new ConnectionQuery();
		$relation_query->set( 'relation', RELATION_0_NAME );
		$relation_connections = $this->find_storage_connections( $relation_query );
		$this->assert_connection_ids(
			[ $other_from_to_first->id, $from_to_second->id, $from_to_first->id ],
			$relation_connections
		);
		self::assertNotContains(
			$other_relation_connection->id,
			$this->connection_ids( $relation_connections )
		);
	}

	public function test_id_has_priority_over_endpoint_filters_and_keeps_relation_boundary(): void
	{
		$connection = $this->create_connection(
			RELATION_0_NAME,
			$this->page_ids[0],
			$this->post_ids[0]
		);

		$query = new ConnectionQuery( 999999, 999998, 999997 );
		$query->set( 'id', $connection->id );
		$this->assert_connection_ids(
			[ $connection->id ],
			$this->find_connections( RELATION_0_NAME, $query )
		);

		$other_relation_name = 'id-priority-other-relation';
		$this->register_relation( $other_relation_name );
		self::assertTrue( $this->find_connections( $other_relation_name, $query )->isEmpty() );
	}

	public function test_parameterizes_relation_filter_with_sql_metacharacters(): void
	{
		$this->create_connection(
			RELATION_0_NAME,
			$this->page_ids[0],
			$this->post_ids[0]
		);
		$query = new ConnectionQuery();
		$query->set( 'relation', "' OR 1=1 --" );

		self::assertTrue( $this->find_storage_connections( $query )->isEmpty() );
	}

	public function test_empty_storage_query_and_unmatched_relation_query_are_empty(): void
	{
		self::assertTrue( $this->find_storage_connections( new ConnectionQuery() )->isEmpty() );

		$query = new ConnectionQuery();
		$query->set( 'both', 999999 );
		self::assertTrue( $this->find_connections( RELATION_0_NAME, $query )->isEmpty() );
	}

	public function test_preserves_duplicate_connection_rows_as_distinct_items(): void
	{
		$relation_name = 'duplicatable-query-relation';
		$this->register_relation( $relation_name, true );
		$first = $this->create_connection(
			$relation_name,
			$this->page_ids[0],
			$this->post_ids[0]
		);
		$second = $this->create_connection(
			$relation_name,
			$this->page_ids[0],
			$this->post_ids[0]
		);

		$query = new ConnectionQuery();
		$query->set( 'both', $this->page_ids[0] );

		$this->assert_connection_ids(
			[ $first->id, $second->id ],
			$this->find_connections( $relation_name, $query )
		);
	}

	public function test_aggregates_repeated_meta_keys_without_duplicating_connection(): void
	{
		$query = new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] );
		$connection = $this->client->getRelation( RELATION_0_NAME )->createConnection( $query );
		$meta = new MetaCollection();
		$meta->add( new Meta( 'tag', 'first' ) );
		$meta->add( new Meta( 'tag', 'second' ) );
		$meta->add( new Meta( 'other', 'value' ) );
		$this->client->getStorage()->addConnectionMeta( $connection->id, $meta );

		$find_query = new ConnectionQuery();
		$find_query->set( 'id', $connection->id );
		$found = $this->find_connections( RELATION_0_NAME, $find_query );

		self::assertCount( 1, $found );
		self::assertSame(
			$this->canonicalize_meta_values(
				[
					'tag'   => [ 'first', 'second' ],
					'other' => [ 'value' ],
				]
			),
			$this->canonicalize_meta_values( $found->first()->meta->toArray() )
		);
	}

	private function create_connection(
		string $relation,
		int $from,
		int $to,
		int $order = 0
	): \iTRON\wpConnections\Connection
	{
		$query = new ConnectionQuery( $from, $to );
		$query->set( 'order', $order );

		return $this->client->getRelation( $relation )->createConnection( $query );
	}

	private function create_entity( string $post_type, string $title ): int
	{
		return self::factory()->post->create(
			[
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_type'   => $post_type,
			]
		);
	}

	private function register_relation( string $name, bool $duplicatable = false ): void
	{
		$query = new RelationQuery();
		$query->set( 'name', $name );
		$query->set( 'from', 'page' );
		$query->set( 'to', 'post' );
		$query->set( 'cardinality', 'm-m' );
		$query->set( 'duplicatable', $duplicatable );
		$this->client->registerRelation( $query );
	}

	private function find_connections(
		string $relation,
		?ConnectionQuery $query = null
	): ConnectionCollection
	{
		global $wpdb;
		$wpdb->last_error = '';
		$connections = $this->client->getRelation( $relation )->findConnections( $query );
		self::assertSame( '', $wpdb->last_error );

		return $connections;
	}

	private function find_storage_connections( ConnectionQuery $query ): ConnectionCollection
	{
		global $wpdb;
		$wpdb->last_error = '';
		$connections = $this->client->getStorage()->findConnections( $query );
		self::assertSame( '', $wpdb->last_error );

		return $connections;
	}

	private function connection_ids( iterable $connections ): array
	{
		$ids = [];
		foreach ( $connections as $connection ) {
			$ids[] = $connection->id;
		}

		return $ids;
	}

	private function assert_connection_ids( array $expected, iterable $connections ): void
	{
		$actual = $this->connection_ids( $connections );
		sort( $expected, SORT_NUMERIC );
		sort( $actual, SORT_NUMERIC );
		self::assertSame( $expected, $actual );
	}

	private function canonicalize_meta_values( array $meta ): array
	{
		ksort( $meta );
		foreach ( $meta as &$values ) {
			sort( $values, SORT_STRING );
		}
		unset( $values );

		return $meta;
	}
}
