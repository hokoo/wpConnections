<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Query\Connection;
use iTRON\wpConnections\Query\Meta;

class QueryMetaMaterializationTest extends WPConnectionsTestCase
{
	public function test_create_connection_with_one_query_meta_pair_round_trips(): void
	{
		$query = new Connection( $this->page_ids[0], $this->post_ids[0] );
		$query->meta->fromArray( [ 'query-key' => 'query-value' ] );

		$created = $this->client->getRelation( RELATION_0_NAME )->createConnection( $query );
		$lookup = new Connection();
		$lookup->set( 'id', $created->id );
		$persisted = $this->client->getRelation( RELATION_0_NAME )->findConnections( $lookup )->first();

		self::assertGreaterThan( 0, $created->id );
		self::assertSame( [ 'query-key' => [ 'query-value' ] ], $created->meta->toArray() );
		self::assertSame( $created->id, $persisted->id );
		self::assertCount( 1, $persisted->meta );
		self::assertInstanceOf( Meta::class, $query->meta->first() );
		self::assertSame( 'query-key', $persisted->meta->first()->getKey() );
		self::assertSame( 'query-value', $persisted->meta->first()->getValue() );
		self::assertSame( [ 'query-key' => [ 'query-value' ] ], $persisted->meta->toArray() );
	}
}
