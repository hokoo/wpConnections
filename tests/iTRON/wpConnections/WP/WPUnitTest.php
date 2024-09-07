<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Client;
use iTRON\wpConnections\Query\Connection;
use iTRON\wpConnections\Query\Relation;
use PHPUnit\Framework\TestCase;

class WPUnitTest extends TestCase
{
	const CLIENT_NAME = 'client-test';
	const RELATION_NAME = 'relation-test';

	protected Client $client;
	protected int $post_id;
	protected int $page_id;


	protected function setUp(): void
	{
		// Create client
		$this->client = new Client( self::CLIENT_NAME );
		$relation = new Relation();
		$relation->set( 'name', self::RELATION_NAME );
		$relation->set( 'from', 'page' );
		$relation->set( 'to', 'post' );
		$relation->set( 'cardinality', 'm-m' );

		$this->client->registerRelation( $relation );

		// Create some posts and pages.
		$this->post_id = wp_insert_post( [
			'post_title' => 'Post 1',
			'post_content' => 'Post 1 content',
			'post_status' => 'publish',
			'post_type' => 'post',
		] );
		$this->page_id = wp_insert_post( [
			'post_title' => 'Page 1',
			'post_content' => 'Page 1 content',
			'post_status' => 'publish',
			'post_type' => 'page',
		] );

		self::assertIsInt( $this->post_id );
		self::assertIsInt( $this->page_id );

		// Echo the post and page IDs
		echo 'Post ID: ' . $this->post_id . PHP_EOL;
		echo 'Page ID: ' . $this->page_id . PHP_EOL;
	}

	public function testCreateConnection()
	{
		$connection_query = new Connection(
			$this->page_id,
			$this->post_id
		);

		$created_connection = $this->client->getRelation( self::RELATION_NAME )->createConnection( $connection_query );

		self::assertEquals( $this->page_id, $created_connection->from );
		self::assertEquals( $this->post_id, $created_connection->to );

		self::assertTrue(
			$this->client->getRelation( self::RELATION_NAME )->hasConnectionID(
				$created_connection->id
			)
		);

		$connection = $this->client->getRelation( self::RELATION_NAME )->findConnections( $connection_query )->first();

		self::assertEquals( $connection_query->id, $connection->id );
		self::assertEquals( $connection_query->from, $connection->from );
		self::assertEquals( $connection_query->to, $connection->to );
	}
}
