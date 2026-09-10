<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Exceptions\ConnectionWrongData;
use iTRON\wpConnections\Meta;
use iTRON\wpConnections\Query\Connection as ConnectionQuery;
use iTRON\wpConnections\Query\Relation as RelationQuery;

class CardinalityTest extends WPConnectionsTestCase
{
	public function test_one_to_many_rejects_a_second_from_for_an_occupied_to(): void
	{
		$relation = $this->register_relation( 'cardinality-one-to-many', '1-m' );
		$other_page_id = $this->create_page( 'Other one-to-many page' );
		$created_calls = 0;
		$created_callback = static function () use ( &$created_calls ): void {
			$created_calls++;
		};
		add_action( 'wpConnections/relation/created', $created_callback );

		$relation->createConnection( new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] ) );
		$allowed = $relation->createConnection(
			new ConnectionQuery( $this->page_ids[0], $this->post_ids[1] )
		);

		self::assertSame( $this->page_ids[0], $allowed->from );
		self::assertSame( $this->post_ids[1], $allowed->to );

		$exception = null;
		try {
			$relation->createConnection( new ConnectionQuery( $other_page_id, $this->post_ids[0] ) );
		} catch ( ConnectionWrongData $caught_exception ) {
			$exception = $caught_exception;
		} finally {
			remove_action( 'wpConnections/relation/created', $created_callback );
		}

		self::assertInstanceOf(
			ConnectionWrongData::class,
			$exception,
			'The 1-m relation accepted a second from endpoint for an occupied to endpoint.'
		);
		self::assertSame( 302, $exception->getCode() );
		self::assertSame( 2, $created_calls, 'A rejected create emitted a success hook.' );
		$this->assert_persisted_pairs(
			$relation,
			[
				[ $this->page_ids[0], $this->post_ids[0] ],
				[ $this->page_ids[0], $this->post_ids[1] ],
			]
		);
	}

	public function test_many_to_one_rejects_a_second_to_for_an_occupied_from(): void
	{
		$relation = $this->register_relation( 'cardinality-many-to-one', 'm-1' );
		$other_page_id = $this->create_page( 'Other many-to-one page' );
		$created_calls = 0;
		$created_callback = static function () use ( &$created_calls ): void {
			$created_calls++;
		};
		add_action( 'wpConnections/relation/created', $created_callback );

		$relation->createConnection( new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] ) );
		$allowed = $relation->createConnection(
			new ConnectionQuery( $other_page_id, $this->post_ids[0] )
		);

		self::assertSame( $other_page_id, $allowed->from );
		self::assertSame( $this->post_ids[0], $allowed->to );

		$exception = null;
		try {
			$relation->createConnection( new ConnectionQuery( $this->page_ids[0], $this->post_ids[1] ) );
		} catch ( ConnectionWrongData $caught_exception ) {
			$exception = $caught_exception;
		} finally {
			remove_action( 'wpConnections/relation/created', $created_callback );
		}

		self::assertInstanceOf(
			ConnectionWrongData::class,
			$exception,
			'The m-1 relation accepted a second to endpoint for an occupied from endpoint.'
		);
		self::assertSame( 302, $exception->getCode() );
		self::assertSame( 2, $created_calls, 'A rejected create emitted a success hook.' );
		$this->assert_persisted_pairs(
			$relation,
			[
				[ $this->page_ids[0], $this->post_ids[0] ],
				[ $other_page_id, $this->post_ids[0] ],
			]
		);
	}

	/**
	 * @dataProvider create_cardinality_provider
	 */
	public function test_enforces_complete_create_cardinality_matrix(
		string $cardinality,
		array $allowed_pairs,
		array $rejected_pairs
	): void {
		$relation = $this->register_relation( 'create-matrix-' . strtolower( str_replace( '-', '', $cardinality ) ), $cardinality );
		$endpoints = $this->get_matrix_endpoints();
		$persisted_pairs = [];

		foreach ( $allowed_pairs as $pair ) {
			[ $from, $to ] = $this->resolve_pair( $pair, $endpoints );
			$connection = $relation->createConnection( new ConnectionQuery( $from, $to ) );
			self::assertGreaterThan( 0, $connection->id );
			$persisted_pairs[] = [ $from, $to ];
		}

		$created_calls = 0;
		$created_callback = static function () use ( &$created_calls ): void {
			$created_calls++;
		};
		add_action( 'wpConnections/relation/created', $created_callback );

		try {
			foreach ( $rejected_pairs as $pair ) {
				[ $from, $to ] = $this->resolve_pair( $pair, $endpoints );
				$this->assert_create_has_cardinality_error( $relation, $from, $to );
			}
		} finally {
			remove_action( 'wpConnections/relation/created', $created_callback );
		}

		self::assertSame( 0, $created_calls, 'A rejected matrix create emitted a success hook.' );
		$this->assert_persisted_pairs( $relation, $persisted_pairs );
	}

	public function create_cardinality_provider(): array
	{
		return [
			'one to one'   => [
				'1-1',
				[ [ 'a', 'x' ], [ 'b', 'y' ] ],
				[ [ 'a', 'y' ], [ 'b', 'x' ] ],
			],
			'one to many'  => [
				'1-m',
				[ [ 'a', 'x' ], [ 'a', 'y' ] ],
				[ [ 'b', 'x' ], [ 'b', 'y' ] ],
			],
			'many to one'  => [
				'm-1',
				[ [ 'a', 'x' ], [ 'b', 'x' ] ],
				[ [ 'a', 'y' ], [ 'b', 'y' ] ],
			],
			'many to many' => [
				'm-m',
				[ [ 'a', 'x' ], [ 'a', 'y' ], [ 'b', 'x' ], [ 'b', 'y' ] ],
				[],
			],
		];
	}

	/**
	 * @dataProvider update_cardinality_provider
	 */
	public function test_connection_update_enforces_cardinality_and_preserves_rejected_state(
		string $cardinality,
		string $updated_from,
		string $updated_to,
		bool $allowed
	): void {
		$this->assert_update_cardinality( 'connection', $cardinality, $updated_from, $updated_to, $allowed );
	}

	/**
	 * @dataProvider update_cardinality_provider
	 */
	public function test_relation_query_update_enforces_cardinality_and_preserves_rejected_state(
		string $cardinality,
		string $updated_from,
		string $updated_to,
		bool $allowed
	): void {
		$this->assert_update_cardinality( 'relation', $cardinality, $updated_from, $updated_to, $allowed );
	}

	public function update_cardinality_provider(): array
	{
		return [
			'1-1 rejects occupied from' => [ '1-1', 'a', 'y', false ],
			'1-1 rejects occupied to'   => [ '1-1', 'b', 'x', false ],
			'1-m rejects occupied to'   => [ '1-m', 'b', 'x', false ],
			'1-m permits from move'     => [ '1-m', 'a', 'y', true ],
			'm-1 rejects occupied from' => [ 'm-1', 'a', 'y', false ],
			'm-1 permits to move'       => [ 'm-1', 'b', 'x', true ],
			'm-m permits endpoint move' => [ 'm-m', 'a', 'y', true ],
		];
	}

	private function register_relation( string $name, string $cardinality ): \iTRON\wpConnections\Relation
	{
		$query = new RelationQuery();
		$query->set( 'name', $name );
		$query->set( 'from', 'page' );
		$query->set( 'to', 'post' );
		$query->set( 'cardinality', $cardinality );

		return $this->client->registerRelation( $query );
	}

	private function create_page( string $title ): int
	{
		return self::factory()->post->create(
			[
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_type'   => 'page',
			]
		);
	}

	private function get_matrix_endpoints(): array
	{
		return [
			'a' => $this->page_ids[0],
			'b' => $this->create_page( 'Cardinality matrix page B' ),
			'x' => $this->post_ids[0],
			'y' => $this->post_ids[1],
		];
	}

	private function resolve_pair( array $pair, array $endpoints ): array
	{
		return [ $endpoints[ $pair[0] ], $endpoints[ $pair[1] ] ];
	}

	private function assert_create_has_cardinality_error(
		\iTRON\wpConnections\Relation $relation,
		int $from,
		int $to
	): void {
		try {
			$relation->createConnection( new ConnectionQuery( $from, $to ) );
		} catch ( ConnectionWrongData $exception ) {
			self::assertSame( 302, $exception->getCode() );
			return;
		}

		self::fail( 'The relation accepted a connection that violates cardinality.' );
	}

	private function assert_update_cardinality(
		string $entrypoint,
		string $cardinality,
		string $updated_from,
		string $updated_to,
		bool $allowed
	): void {
		$relation = $this->register_relation(
			'update-' . $entrypoint . '-' . strtolower( str_replace( '-', '', $cardinality ) ),
			$cardinality
		);
		$endpoints = $this->get_matrix_endpoints();
		$relation->createConnection( new ConnectionQuery( $endpoints['a'], $endpoints['x'] ) );
		$target = $relation->createConnection( new ConnectionQuery( $endpoints['b'], $endpoints['y'] ) );
		$target->title = 'Original title';
		$target->order = 7;
		$target->meta->add( new Meta( 'cardinality-marker', 'preserved' ) );

		// A same-endpoint, non-endpoint update must exclude the current row.
		$target->update();
		$original_title = 'Original title';
		if ( 'relation' === $entrypoint ) {
			$same_endpoints = new ConnectionQuery( $target->from, $target->to );
			$same_endpoints->set( 'id', $target->id );
			$same_endpoints->set( 'title', 'Relation baseline title' );
			$same_endpoints->set( 'order', 7 );
			$relation->updateConnection( $same_endpoints );
			$original_title = 'Relation baseline title';
		}

		$from = $endpoints[ $updated_from ];
		$to = $endpoints[ $updated_to ];
		$exception = null;

		try {
			if ( 'connection' === $entrypoint ) {
				$target->from = $from;
				$target->to = $to;
				$target->title = 'Updated title';
				$target->order = 0;
				$target->update();
			} else {
				$query = new ConnectionQuery( $from, $to );
				$query->set( 'id', $target->id );
				$query->set( 'title', 'Updated title' );
				$query->set( 'order', 0 );
				$relation->updateConnection( $query );
			}
		} catch ( ConnectionWrongData $caught_exception ) {
			$exception = $caught_exception;
		}

		$persisted = $this->find_connection( $relation, $target->id );
		if ( $allowed ) {
			self::assertNull( $exception );
			self::assertSame( $from, $persisted->from );
			self::assertSame( $to, $persisted->to );
			self::assertSame( 'Updated title', $persisted->title );
			self::assertSame( 0, $persisted->order );
		} else {
			self::assertInstanceOf( ConnectionWrongData::class, $exception );
			self::assertSame( 302, $exception->getCode() );
			self::assertSame( $endpoints['b'], $persisted->from );
			self::assertSame( $endpoints['y'], $persisted->to );
			self::assertSame( $original_title, $persisted->title );
			self::assertSame( 7, $persisted->order );
		}

		self::assertSame( [ 'cardinality-marker' => [ 'preserved' ] ], $persisted->meta->toArray() );
		self::assertCount( 2, $relation->findConnections() );
	}

	private function find_connection(
		\iTRON\wpConnections\Relation $relation,
		int $connection_id
	): \iTRON\wpConnections\Connection {
		$query = new ConnectionQuery();
		$query->set( 'id', $connection_id );

		return $relation->findConnections( $query )->first();
	}

	private function assert_persisted_pairs( \iTRON\wpConnections\Relation $relation, array $expected ): void
	{
		$actual = [];
		foreach ( $relation->findConnections()->getIterator() as $connection ) {
			$actual[] = [ $connection->from, $connection->to ];
		}

		sort( $expected );
		sort( $actual );

		self::assertSame( $expected, $actual );
	}
}
