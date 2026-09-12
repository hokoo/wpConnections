<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Client;
use iTRON\wpConnections\Helpers\Database;
use iTRON\wpConnections\Internal\RestRouteRegistry;
use iTRON\wpConnections\Query\Relation;
use iTRON\wpConnections\WPStorage;

abstract class WPConnectionsTestCase extends \WP_UnitTestCase
{
	protected Client $client;
	protected array $post_ids = [];
	protected array $page_ids = [];
	protected \WP_REST_Server $rest_server;

	private array $wpdb_tables_before_client = [];

	public function set_up()
	{
		parent::set_up();

		global $wpdb;
		$this->wpdb_tables_before_client = $wpdb->tables;

		add_filter( 'wpConnections/storage/installOnInit', '__return_true', 10, 2 );

		$this->client = new Client( CLIENT_NAME );
		$this->register_relations();
		$this->create_posts();
	}

	public function tear_down()
	{
		try {
			if ( class_exists( RestRouteRegistry::class ) ) {
				RestRouteRegistry::instance()->deactivateClient( $this->client );
			}
			$this->drop_client_tables();
		} finally {
			parent::tear_down();
		}
	}

	protected function set_up_rest_server(): void
	{
		$GLOBALS['wp_rest_server'] = null;
		$this->rest_server = rest_get_server();
	}

	protected function tear_down_rest_server(): void
	{
		$GLOBALS['wp_rest_server'] = null;
		wp_set_current_user( 0 );
	}

	protected function get_rest_route( string $suffix = '' ): string
	{
		return '/wp-connections/v1/client/' . $this->client->getName() . $suffix;
	}

	protected function dispatch_rest_request(
		string $method,
		string $route,
		array $payload = []
	): \WP_REST_Response
	{
		$request = new \WP_REST_Request( $method, $route );
		$request->set_body_params( $payload );

		return $this->rest_server->dispatch( $request );
	}

	protected function authenticate_as_administrator(): int
	{
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	protected function authenticate_as_anonymous(): void
	{
		wp_set_current_user( 0 );
	}

	protected function assert_rest_error_response(
		string $expected_code,
		int $expected_status,
		\WP_REST_Response $response
	): void
	{
		$data = $response->get_data();

		self::assertSame( $expected_status, $response->get_status() );
		self::assertIsArray( $data );
		self::assertSame( $expected_code, $data['code'] ?? null );
	}

	private function register_relations(): void
	{
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
	}

	private function create_posts(): void
	{
		$this->post_ids = [
			self::factory()->post->create(
				[
					'post_title'   => 'Post 1',
					'post_content' => 'Post 1 content',
					'post_status'  => 'publish',
					'post_type'    => 'post',
				]
			),
			self::factory()->post->create(
				[
					'post_title'   => 'Post 2',
					'post_content' => 'Post 2 content',
					'post_status'  => 'publish',
					'post_type'    => 'post',
				]
			),
		];

		$this->page_ids = [
			self::factory()->post->create(
				[
					'post_title'   => 'Page 1',
					'post_content' => 'Page 1 content',
					'post_status'  => 'publish',
					'post_type'    => 'page',
				]
			),
		];
	}

	private function drop_client_tables(): void
	{
		global $wpdb;

		$postfix = Database::normalize_table_name( $this->client->getName() );
		$table_keys = [
			WPStorage::META_TABLE_PREFIX . $postfix,
			WPStorage::CONNECTIONS_TABLE_PREFIX . $postfix,
		];

		foreach ( $table_keys as $table_key ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$table_key}`" );
			unset( $wpdb->{$table_key} );
		}

		$wpdb->tables = $this->wpdb_tables_before_client;
	}
}
