<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Client;
use iTRON\wpConnections\ClientRestApi;
use iTRON\wpConnections\ConnectionCollection;
use iTRON\wpConnections\Exceptions\ConnectionWrongData;
use iTRON\wpConnections\Meta;
use iTRON\wpConnections\Query\Connection;
use iTRON\wpConnections\Query\Relation;
use PHPUnit\Framework\TestCase;

use Ramsey\Collection\Exception\OutOfBoundsException;

use function PHPUnit\Framework\assertEquals;

class WPUnitTest extends TestCase
{
	protected Client $client;
	protected array $post_ids;
	protected array $page_ids;

	protected function setUp(): void
	{
		// Create client
		$this->client = new Client( CLIENT_NAME );
		$relation = new Relation();
		$relation->set( 'name', RELATION_0_NAME );
		$relation->set( 'from', 'page' );
		$relation->set( 'to', 'post' );
		$relation->set( 'cardinality', 'm-m' );

		$this->client->registerRelation( $relation );

		$relation = new Relation();
		$relation->set( 'name', RELATION_1_NAME );
		$relation->set( 'from', 'page' );
		$relation->set( 'to', 'post' );
		$relation->set( 'cardinality', '1-m' );

		$this->client->registerRelation( $relation );

		// Create some posts and pages.
		$this->post_ids[0] = wp_insert_post( [
			'post_title' => 'Post 1',
			'post_content' => 'Post 1 content',
			'post_status' => 'publish',
			'post_type' => 'post',
		] );
		$this->post_ids[1] = wp_insert_post( [
			'post_title' => 'Post 2',
			'post_content' => 'Post 2 content',
			'post_status' => 'publish',
			'post_type' => 'post',
		] );
		$this->page_ids[0] = wp_insert_post( [
			'post_title' => 'Page 1',
			'post_content' => 'Page 1 content',
			'post_status' => 'publish',
			'post_type' => 'page',
		] );

		self::assertIsInt( $this->post_ids[0] );
		self::assertIsInt( $this->page_ids[0] );

		// Echo the post and page IDs
		echo PHP_EOL;
		echo 'Post ID: ' . $this->post_ids[0] . PHP_EOL;
		echo 'Page ID: ' . $this->page_ids[0] . PHP_EOL;
	}

	public function testCreateConnection()
	{
		$connection_query = new Connection(
			$this->page_ids[0],
			$this->post_ids[0]
		);

		$created_connection = $this->client->getRelation( RELATION_0_NAME )->createConnection( $connection_query );

		self::assertEquals( $this->page_ids[0], $created_connection->from );
		self::assertEquals( $this->post_ids[0], $created_connection->to );

		self::assertTrue(
			$this->client->getRelation( RELATION_0_NAME )->hasConnectionID(
				$created_connection->id
			)
		);

		$connection = $this->client->getRelation( RELATION_0_NAME )->findConnections( $connection_query )->first();

		self::assertEquals( $connection_query->id, $connection->id );
		self::assertEquals( $connection_query->from, $connection->from );
		self::assertEquals( $connection_query->to, $connection->to );

		return $connection;
	}

	public function testFindConnections() {
		// Create new page.
		$page_id = wp_insert_post( [
			'post_title' => 'Test Find Connections',
			'post_content' => 'Page content',
			'post_status' => 'publish',
			'post_type' => 'page',
		] );

		// Create new post.
		$post_id = wp_insert_post( [
			'post_title' => 'Test Find Connections',
			'post_content' => 'Post content',
			'post_status' => 'publish',
			'post_type' => 'post',
		] );

		// Create new connection.
		$connection_query = new Connection(
			$page_id,
			$post_id
		);
		$connection_query->title = 'Test Find Connections';

		$connection = $this->client->getRelation( RELATION_0_NAME )->createConnection( $connection_query );

		// Find the connection by ID.
		$find_query = new Connection();
		$find_query->id = $connection->id;

		$found_connections = $this->client->getRelation( RELATION_0_NAME )->findConnections( $find_query );

		self::assertEquals( 1, $found_connections->count() );
		self::assertEquals( $connection->id, $found_connections->first()->id );
	}

	/**
	 * @depends testCreateConnection
	 */
	public function testUpdateConnection( $connection )
	{
		$title = 'New test title';
		$order = 10;

		// Create new page.
		$page_id = wp_insert_post( [
			'post_title' => 'Page 2',
			'post_content' => 'Page 2 content',
			'post_status' => 'publish',
			'post_type' => 'page',
		] );

		// Create new post.
		$post_id = wp_insert_post( [
			'post_title' => 'Post 2',
			'post_content' => 'Post 2 content',
			'post_status' => 'publish',
			'post_type' => 'post',
		] );

		// Find the connection.
		$connection_query = new Connection(
			$page_id,
			$post_id
		);
		$connection_query->title = 'Test Update Connection';

		$connection = $this->client->getRelation( RELATION_0_NAME )->createConnection( $connection_query );

		// Create new post.
		$post_id = wp_insert_post( [
			'post_title' => 'Post 3',
			'post_content' => 'Post 3 content',
			'post_status' => 'publish',
			'post_type' => 'post',
		] );

		$connection->title = $title;
		$connection->order = $order;
		$connection->to = $post_id;

		$meta0 = new Meta(
			'key0',
			'value0'
		);
		$connection->meta->add( $meta0 );

		// Update the connection.
		$connection->update();

		// Find the connection again.
		$connection = $this->client->getRelation( RELATION_0_NAME )->findConnections( $connection_query )->first();

		self::assertEquals( $title, $connection->title );
		self::assertEquals( $order, $connection->order );
		self::assertEquals( $page_id, $connection->from );
		self::assertEquals( $post_id, $connection->to );
		self::assertEquals( 1, $connection->meta->count() );
		self::assertEquals( $meta0, $connection->meta->first() );

		$meta1 = new Meta(
			'key1',
			'value1'
		);
		$connection->meta->clear();
		$connection->meta->add( $meta1 );

		// Update the connection.
		$connection->update();

		// Find the connection again.
		$connection = $this->client->getRelation( RELATION_0_NAME )->findConnections( $connection_query )->first();

		self::assertEquals( 1, $connection->meta->count() );
		self::assertEquals( $meta1, $connection->meta->first() );

		// Test throwing exception when updating uninitialized connection.
		$connection_query = new Connection(
			$this->page_ids[0],
			$this->post_ids[0]
		);

		$connection = new \iTRON\wpConnections\Connection( $connection_query );

		$this->expectException( ConnectionWrongData::class );
		$connection->update();
	}

	public function testRestUpdateMeta() {
		// Create new page.
		$page_id = wp_insert_post( [
			'post_title' => 'Page for REST API tests',
			'post_content' => 'Page content',
			'post_status' => 'publish',
			'post_type' => 'page',
		] );

		// Create new post.
		$post_id = wp_insert_post( [
			'post_title' => 'Post for REST API tests',
			'post_content' => 'Post content',
			'post_status' => 'publish',
			'post_type' => 'post',
		] );

		// Find the connection.
		$connection_query = new Connection(
			$page_id,
			$post_id
		);

		$connection = $this->client->getRelation( RELATION_0_NAME )->createConnection( $connection_query );
		$meta00 = new Meta( 'key0', 'value00' );
		$meta01 = new Meta( 'key0', 'value01' );
		$connection->meta->add( $meta00 );
		$connection->meta->add( $meta01 );
		$connection->update();

		$request = new \WP_REST_Request( 'POST' );
		$meta1 = new Meta( 'key1', 'value1' );
		$request->set_param( 'connectionID', $connection->id );
		$request->set_param( 'meta', [$meta1->toArray()] );
		$request->set_param( 'relation', RELATION_0_NAME );

		$restapi = new ClientRestApi( $this->client );
		$response = $restapi->updateConnectionMeta( $request );

		$connection->meta->add( $meta1 );
		self::assertEquals( 'WP_REST_Response', get_class( $response ) );
		self::assertEquals( $connection, $response->get_data()['updated'] );

		$meta02 = new Meta( 'key0', 'value02' );
		$request = new \WP_REST_Request( 'PATCH' );
		$request->set_param( 'connectionID', $connection->id );
		$request->set_param( 'meta', [$meta02->toArray()] );
		$request->set_param( 'relation', RELATION_0_NAME );

		$response = $restapi->updateConnectionMeta( $request );
		$connection->meta->remove( $meta00 );
		$connection->meta->remove( $meta01 );
		$connection->meta->add( $meta02 );

		self::assertEquals( 'WP_REST_Response', get_class( $response ) );
		self::assertEquals( $connection->meta->toArray(), $response->get_data()['updated']->meta->toArray() );

		$meta3 = new Meta( 'key3', 'value3' );
		$meta4 = new Meta( 'key4', 'value4' );
		$request = new \WP_REST_Request( 'PUT' );
		$request->set_param( 'connectionID', $connection->id );
		$request->set_param( 'meta', [$meta3->toArray(), $meta4->toArray()] );
		$request->set_param( 'relation', RELATION_0_NAME );

		$response = $restapi->updateConnectionMeta( $request );
		$connection->meta->clear();
		$connection->meta->add( $meta3 );
		$connection->meta->add( $meta4 );

		self::assertEquals( 'WP_REST_Response', get_class( $response ) );
		self::assertEquals( $connection->meta->toArray(), $response->get_data()['updated']->meta->toArray() );
	}

	public function testCardinality() {
		$this->client->getRelation( RELATION_1_NAME )->createConnection(
			new Connection(
			$this->page_ids[0],
			$this->post_ids[0]
		) );
		$this->client->getRelation( RELATION_1_NAME )->createConnection(
			new Connection(
			$this->page_ids[0],
			$this->post_ids[1]
		) );

		// We expect to have no one Exception Cardinality violation here.
		$this->assertTrue(true);
	}
}
