<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Client;
use iTRON\wpConnections\Exceptions\ConnectionNotFound;
use iTRON\wpConnections\Exceptions\ConnectionRelationMismatch;
use iTRON\wpConnections\Internal\DeletedPostRepairRuntime;
use iTRON\wpConnections\Query\Connection as ConnectionQuery;
use iTRON\wpConnections\Query\Meta as QueryMeta;
use iTRON\wpConnections\Query\Relation as RelationQuery;
use iTRON\wpConnections\WPStorage;
use PHPUnit\Framework\TestCase;

class ConnectionUpdateConcurrencyTest extends TestCase
{
	private const RELATION = 'update-concurrency';

	private Client $client;
	private array $post_ids = [];
	private array $wpdb_tables_before = [];

	protected function setUp(): void
	{
		parent::setUp();
		global $wpdb;

		wp_cache_flush();
		$this->post_ids = [];
		$this->wpdb_tables_before = $wpdb->tables;
		add_filter( 'wpConnections/storage/installOnInit', '__return_true', 10, 2 );
		$this->client = new Client( 'update-concurrency-' . substr( hash( 'sha256', $this->getName() ), 0, 12 ) );
		$relation = new RelationQuery();
		$relation->set( 'name', self::RELATION );
		$relation->set( 'from', 'page' );
		$relation->set( 'to', 'post' );
		$relation->set( 'cardinality', 'm-m' );
		$this->client->registerRelation( $relation );
		$this->post_ids[] = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Update race source' ] );
		$this->post_ids[] = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Update race target' ] );
		self::assertGreaterThan( 0, $this->post_ids[0] );
		self::assertGreaterThan( 0, $this->post_ids[1] );
	}

	protected function tearDown(): void
	{
		global $wpdb;

		try {
			$wpdb->query( 'ROLLBACK' );
			$this->client->dispose();
			foreach ( [ $this->meta_table(), $this->connections_table() ] as $table ) {
				$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
			}
			unset( $wpdb->{$this->client->getStorage()->get_meta_table()} );
			unset( $wpdb->{$this->client->getStorage()->get_connections_table()} );
			foreach ( $this->post_ids as $post_id ) {
				if ( 0 < $post_id ) {
					wp_delete_post( $post_id, true );
				}
			}
			delete_option( 'wpconnections_storage_owner_' . hash( 'sha256', str_replace( '-', '_', $this->client->getName() ) ) );
			$wpdb->tables = $this->wpdb_tables_before;
			DeletedPostRepairRuntime::instance()->resetForTests();
			remove_filter( 'wpConnections/storage/installOnInit', '__return_true', 10 );
		} finally {
			parent::tearDown();
		}
	}

	public function test_delete_before_update_lock_raises_not_found_without_orphan_metadata(): void
	{
		global $wpdb;
		$connection = $this->create_connection();
		$connection->meta->clear();
		$connection->meta->fromArray( [ 'replacement' => [ 'value' ] ] );
		$secondary = $this->secondary_connection();
		self::assertNotSame( (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' ), $secondary->thread_id );
		$deleted = false;
		$before_transaction = function ( string $sql ) use ( $secondary, $connection, &$deleted ): string {
			if ( ! $deleted && 'START TRANSACTION' === trim( $sql ) ) {
				$deleted = true;
				self::assertTrue( $secondary->query( $this->delete_sql( $connection->id ) ) );
				self::assertSame( 2, $secondary->affected_rows );
			}
			return $sql;
		};
		add_filter( 'query', $before_transaction );

		try {
			$failure = null;
			try {
				$connection->update();
			} catch ( \Throwable $caught ) {
				$failure = $caught;
			}
		} finally {
			remove_filter( 'query', $before_transaction );
			$secondary->close();
		}

		self::assertTrue( $deleted );
		$this->assert_absent( $connection->id );
		self::assertInstanceOf( ConnectionNotFound::class, $failure );
		self::assertSame( 2, $failure->getCode() );
	}

	public function test_relation_change_before_update_lock_preserves_new_owner(): void
	{
		$connection = $this->create_connection();
		$connection->meta->clear();
		$connection->meta->fromArray( [ 'replacement' => [ 'value' ] ] );
		$secondary = $this->secondary_connection();
		$changed = false;
		$before_transaction = function ( string $sql ) use ( $secondary, $connection, &$changed ): string {
			if ( ! $changed && 'START TRANSACTION' === trim( $sql ) ) {
				$changed = true;
				self::assertTrue( $secondary->query( "UPDATE `{$this->connections_table()}` SET `relation` = 'foreign-owner' WHERE `ID` = {$connection->id}" ) );
				self::assertSame( 1, $secondary->affected_rows );
			}
			return $sql;
		};
		add_filter( 'query', $before_transaction );

		try {
			$failure = null;
			try {
				$connection->update();
			} catch ( \Throwable $caught ) {
				$failure = $caught;
			}
		} finally {
			remove_filter( 'query', $before_transaction );
			$secondary->close();
		}

		global $wpdb;
		self::assertTrue( $changed );
		self::assertSame( 'foreign-owner', $wpdb->get_var( $wpdb->prepare( "SELECT `relation` FROM `{$this->connections_table()}` WHERE `ID` = %d", $connection->id ) ) );
		self::assertSame( '1', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$this->meta_table()}` WHERE `connection_id` = %d AND `meta_key` = 'original'", $connection->id ) ) );
		self::assertInstanceOf( ConnectionRelationMismatch::class, $failure );
	}

	public function test_update_lock_makes_competing_delete_wait_then_remove_metadata(): void
	{
		global $wpdb;
		$connection = $this->create_connection();
		$connection->meta->clear();
		$connection->meta->fromArray( [ 'replacement' => [ 'value' ] ] );
		$secondary = $this->secondary_connection();
		$observer = $this->secondary_connection();
		self::assertNotSame( (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' ), $secondary->thread_id );
		$secondary->query( 'SET SESSION innodb_lock_wait_timeout = 5' );
		$started = false;
		$waiting = false;
		$finished_early = null;
		$before_metadata = function ( string $sql ) use ( $secondary, $observer, $connection, &$started, &$waiting, &$finished_early ): string {
			if ( $started || 1 !== preg_match( '/^DELETE FROM ' . preg_quote( $this->meta_table(), '/' ) . ' WHERE /i', trim( $sql ) ) ) {
				return $sql;
			}
			$started = true;
			self::assertTrue( $secondary->query( $this->delete_sql( $connection->id ), MYSQLI_ASYNC ) );
			$waiting = $this->wait_for_query( $observer, $secondary->thread_id, 2.0 );
			$finished_early = $this->async_ready( $secondary, 0.0 );
			return $sql;
		};
		add_filter( 'query', $before_metadata );

		try {
			$connection->update();
			self::assertTrue( $this->async_ready( $secondary, 5.0 ) );
			self::assertTrue( $secondary->reap_async_query() );
			self::assertSame( 2, $secondary->affected_rows );
		} finally {
			remove_filter( 'query', $before_metadata );
			$secondary->close();
			$observer->close();
		}

		self::assertTrue( $started );
		self::assertTrue( $waiting, 'The competing delete never appeared in the database process list.' );
		self::assertFalse( $finished_early, 'The competing delete completed before the aggregate update committed.' );
		$this->assert_absent( $connection->id );
	}

	private function create_connection(): \iTRON\wpConnections\Connection
	{
		$query = new ConnectionQuery( $this->post_ids[0], $this->post_ids[1] );
		$query->set( 'title', 'unchanged' );
		$query->meta->add( new QueryMeta( 'original', 'value' ) );
		return $this->client->getRelation( self::RELATION )->createConnection( $query );
	}

	private function assert_absent( int $id ): void
	{
		global $wpdb;
		self::assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$this->connections_table()}` WHERE `ID` = %d", $id ) ) );
		self::assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$this->meta_table()}` WHERE `connection_id` = %d", $id ) ) );
	}

	private function delete_sql( int $id ): string
	{
		return "DELETE c, m FROM `{$this->connections_table()}` c LEFT JOIN `{$this->meta_table()}` m ON m.connection_id = c.ID WHERE c.ID = {$id}";
	}

	private function connections_table(): string
	{
		global $wpdb;
		return $wpdb->prefix . $this->client->getStorage()->get_connections_table();
	}

	private function meta_table(): string
	{
		global $wpdb;
		return $wpdb->prefix . $this->client->getStorage()->get_meta_table();
	}

	private function secondary_connection(): \mysqli
	{
		$connection = new \mysqli(
			getenv( 'DB_HOST' ) ?: '127.0.0.1',
			getenv( 'DB_USER' ) ?: 'wordpress',
			getenv( 'DB_PASSWORD' ) ?: 'wordpress',
			getenv( 'DB_NAME' ) ?: 'wordpress_test',
			(int) ( getenv( 'DB_PORT' ) ?: 3306 )
		);
		$connection->set_charset( 'utf8mb4' );
		return $connection;
	}

	private function async_ready( \mysqli $connection, float $timeout ): bool
	{
		$read = [ $connection ];
		$error = [ $connection ];
		$reject = [ $connection ];
		$seconds = (int) $timeout;
		return 0 < \mysqli_poll( $read, $error, $reject, $seconds, (int) ( ( $timeout - $seconds ) * 1000000 ) );
	}

	private function wait_for_query( \mysqli $observer, int $thread_id, float $timeout ): bool
	{
		$deadline = microtime( true ) + $timeout;
		do {
			$result = $observer->query( "SELECT `INFO` FROM information_schema.PROCESSLIST WHERE `ID` = {$thread_id}" );
			$row = $result->fetch_assoc();
			$result->free();
			if ( is_array( $row ) && is_string( $row['INFO'] ) && false !== strpos( $row['INFO'], 'DELETE c, m FROM' ) ) {
				return true;
			}
			usleep( 10000 );
		} while ( microtime( true ) < $deadline );
		return false;
	}
}
