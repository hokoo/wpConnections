<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Client;
use iTRON\wpConnections\Helpers\Database;
use iTRON\wpConnections\Query\Relation;
use iTRON\wpConnections\WPStorage;

abstract class WPConnectionsTestCase extends \WP_UnitTestCase
{
	protected Client $client;
	protected array $post_ids = [];
	protected array $page_ids = [];

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
			$this->drop_client_tables();
		} finally {
			parent::tear_down();
		}
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
