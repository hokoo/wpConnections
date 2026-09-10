<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Exceptions\ConnectionWrongData;
use iTRON\wpConnections\Query\Connection as ConnectionQuery;
use iTRON\wpConnections\Query\Relation as RelationQuery;

class CardinalityTest extends WPConnectionsTestCase
{
	public function test_one_to_many_rejects_a_second_from_for_an_occupied_to(): void
	{
		$relation = $this->register_relation( 'cardinality-one-to-many', '1-m' );
		$other_page_id = $this->create_page( 'Other one-to-many page' );

		$relation->createConnection( new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] ) );
		$allowed = $relation->createConnection(
			new ConnectionQuery( $this->page_ids[0], $this->post_ids[1] )
		);

		self::assertSame( $this->page_ids[0], $allowed->from );
		self::assertSame( $this->post_ids[1], $allowed->to );

		try {
			$relation->createConnection( new ConnectionQuery( $other_page_id, $this->post_ids[0] ) );
		} catch ( ConnectionWrongData $exception ) {
			self::assertSame( 302, $exception->getCode() );
			$this->assert_persisted_pairs(
				$relation,
				[
					[ $this->page_ids[0], $this->post_ids[0] ],
					[ $this->page_ids[0], $this->post_ids[1] ],
				]
			);
			return;
		}

		self::fail( 'The 1-m relation accepted a second from endpoint for an occupied to endpoint.' );
	}

	public function test_many_to_one_rejects_a_second_to_for_an_occupied_from(): void
	{
		$relation = $this->register_relation( 'cardinality-many-to-one', 'm-1' );
		$other_page_id = $this->create_page( 'Other many-to-one page' );

		$relation->createConnection( new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] ) );
		$allowed = $relation->createConnection(
			new ConnectionQuery( $other_page_id, $this->post_ids[0] )
		);

		self::assertSame( $other_page_id, $allowed->from );
		self::assertSame( $this->post_ids[0], $allowed->to );

		try {
			$relation->createConnection( new ConnectionQuery( $this->page_ids[0], $this->post_ids[1] ) );
		} catch ( ConnectionWrongData $exception ) {
			self::assertSame( 302, $exception->getCode() );
			$this->assert_persisted_pairs(
				$relation,
				[
					[ $this->page_ids[0], $this->post_ids[0] ],
					[ $other_page_id, $this->post_ids[0] ],
				]
			);
			return;
		}

		self::fail( 'The m-1 relation accepted a second to endpoint for an occupied from endpoint.' );
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
