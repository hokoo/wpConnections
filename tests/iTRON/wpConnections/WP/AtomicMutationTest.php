<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Abstracts\Connection as AbstractConnection;
use iTRON\wpConnections\Abstracts\Storage;
use iTRON\wpConnections\Client;
use iTRON\wpConnections\Connection;
use iTRON\wpConnections\ConnectionCollection;
use iTRON\wpConnections\Exceptions\StorageCapabilityUnavailable;
use iTRON\wpConnections\Exceptions\StorageFailure;
use iTRON\wpConnections\Helpers\Database;
use iTRON\wpConnections\Internal\RestRouteRegistry;
use iTRON\wpConnections\Meta;
use iTRON\wpConnections\MetaCollection;
use iTRON\wpConnections\Query\Connection as ConnectionQuery;
use iTRON\wpConnections\Query\Meta as QueryMeta;
use iTRON\wpConnections\Query\MetaCollection as MetaQueryCollection;
use iTRON\wpConnections\Query\Relation as RelationQuery;
use iTRON\wpConnections\WPStorage;
use PHPUnit\Framework\TestCase;
use Throwable;

class AtomicMutationIncapableStorage extends Storage
{
	public static int $create_calls = 0;

	public function createConnection( ConnectionQuery $connection_query ): int
	{
		self::$create_calls++;
		return 1;
	}

	public function updateConnection( AbstractConnection $connection ): bool
	{
		return true;
	}

	public function deleteSpecificConnections( $connection_ids ): int
	{
		return 0;
	}

	public function deleteByObjectID(
		$object_ids,
		string $relation = '',
		bool $only_from = false,
		bool $only_to = false
	): int {
		return 0;
	}

	public function deleteDirectedConnections(
		?int $from = null,
		?int $to = null,
		string $relation = ''
	): int {
		return 0;
	}

	public function findConnections( ConnectionQuery $params ): ConnectionCollection
	{
		return new ConnectionCollection();
	}

	public function addConnectionMeta( int $object_id, MetaCollection $meta_collection ): void
	{
	}

	public function removeConnectionMeta( int $object_id, MetaQueryCollection $meta_query )
	{
		return 0;
	}
}

class AtomicMutationTest extends TestCase
{
	private const RELATION = 'atomic-mutation-relation';

	private Client $client;
	private array $clients = [];
	private array $post_ids = [];
	private array $wpdb_tables_before = [];

	protected function setUp(): void
	{
		parent::setUp();

		global $wpdb;
		$this->wpdb_tables_before = $wpdb->tables;
		add_filter( 'wpConnections/storage/installOnInit', '__return_true', 10, 2 );

		$this->post_ids['from'] = wp_insert_post(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Atomic mutation from',
			]
		);
		$this->post_ids['to'] = wp_insert_post(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Atomic mutation to',
			]
		);
		self::assertIsInt( $this->post_ids['from'] );
		self::assertIsInt( $this->post_ids['to'] );

		$name = 'atomic-mutation-' . substr( hash( 'sha256', $this->getName() ), 0, 12 );
		$this->client = new Client( $name );
		$this->clients[] = $this->client;
		$this->register_relation( $this->client );
	}

	protected function tearDown(): void
	{
		global $wpdb;

		try {
			$wpdb->query( 'ROLLBACK' );
			foreach ( array_reverse( $this->clients ) as $client ) {
				$this->cleanup_client( $client );
			}
			foreach ( $this->post_ids as $post_id ) {
				wp_delete_post( $post_id, true );
			}
			$wpdb->tables = $this->wpdb_tables_before;
			remove_filter( 'wpConnections/storage/installOnInit', '__return_true', 10 );
		} finally {
			parent::tearDown();
		}
	}

	public function test_create_meta_failure_rolls_back_connection_and_suppresses_success_hooks(): void
	{
		$query = $this->connection_query_with_meta( 'create-failure', 'value' );
		$created_calls = 0;
		$meta_after_calls = 0;
		$created = static function () use ( &$created_calls ): void {
			$created_calls++;
		};
		$meta_after = static function () use ( &$meta_after_calls ): void {
			$meta_after_calls++;
		};
		add_action( 'wpConnections/relation/created', $created );
		add_action( 'wpConnections/storage/addConnectionMeta/after', $meta_after );

		try {
			$failure = $this->capture_database_failure(
				"INSERT INTO `{$this->meta_table()}`",
				function () use ( $query ): void {
					$this->client->getRelation( self::RELATION )->createConnection( $query );
				}
			);
		} finally {
			remove_action( 'wpConnections/relation/created', $created );
			remove_action( 'wpConnections/storage/addConnectionMeta/after', $meta_after );
		}

		self::assertInstanceOf( StorageFailure::class, $failure );
		self::assertSame( 0, $this->connection_count() );
		self::assertSame( 0, $this->meta_count() );
		self::assertSame( 0, $created_calls );
		self::assertSame( 0, $meta_after_calls );
	}

	public function test_aggregate_update_meta_failure_restores_scalar_and_metadata_state(): void
	{
		$connection = $this->create_connection( 'original', 'preserved' );
		$connection->title = 'changed before failed meta replacement';
		$connection->meta = new MetaCollection();
		$connection->meta->add( new Meta( 'replacement', 'rejected' ) );
		$remove_after_calls = 0;
		$remove_after = static function () use ( &$remove_after_calls ): void {
			$remove_after_calls++;
		};
		add_action( 'wpConnections/storage/removeConnectionMeta/after', $remove_after );

		try {
			$failure = $this->capture_database_failure(
				"INSERT INTO `{$this->meta_table()}`",
				static function () use ( $connection ): void {
					$connection->update();
				}
			);
		} finally {
			remove_action( 'wpConnections/storage/removeConnectionMeta/after', $remove_after );
		}

		self::assertInstanceOf( StorageFailure::class, $failure );
		$persisted = $this->find_connection( $connection->id );
		self::assertSame( 'Atomic original', $persisted->title );
		self::assertSame( [ 'original' => [ 'preserved' ] ], $persisted->meta->toArray() );
		self::assertSame( 0, $remove_after_calls );
	}

	public function test_relation_delete_connection_failure_restores_metadata_and_suppresses_success_hook(): void
	{
		$connection = $this->create_connection( 'delete-failure', 'preserved' );
		$deleted_calls = 0;
		$deleted = static function () use ( &$deleted_calls ): void {
			$deleted_calls++;
		};
		add_action( 'wpConnections/storage/deletedSpecificConnections', $deleted );
		$query = new ConnectionQuery();
		$query->set( 'id', $connection->id );

		try {
			$failure = $this->capture_database_failure(
				"DELETE FROM {$this->connections_table()}",
				function () use ( $query ): void {
					$this->client->getRelation( self::RELATION )->detachConnections( $query );
				}
			);
		} finally {
			remove_action( 'wpConnections/storage/deletedSpecificConnections', $deleted );
		}

		self::assertInstanceOf( StorageFailure::class, $failure );
		self::assertSame( 1, $this->connection_count( $connection->id ) );
		self::assertSame( 1, $this->meta_count( $connection->id ) );
		self::assertSame( 0, $deleted_calls );
	}

	public function test_deleted_post_callback_uses_atomic_delete_boundary(): void
	{
		$connection = $this->create_connection( 'deleted-post-failure', 'preserved' );
		self::assertSame(
			10,
			has_action( 'deleted_post', [ $this->client->getStorage(), 'deleteByObjectID' ] )
		);
		$deleted_calls = 0;
		$deleted = static function () use ( &$deleted_calls ): void {
			$deleted_calls++;
		};
		add_action( 'wpConnections/storage/deletedByObjectID', $deleted );

		try {
			$failure = $this->capture_database_failure(
				"DELETE FROM {$this->connections_table()}",
				function (): void {
					wp_delete_post( $this->post_ids['from'], true );
				}
			);
		} finally {
			remove_action( 'wpConnections/storage/deletedByObjectID', $deleted );
		}

		self::assertInstanceOf( StorageFailure::class, $failure );
		self::assertSame( 1, $this->connection_count( $connection->id ) );
		self::assertSame( 1, $this->meta_count( $connection->id ) );
		self::assertSame( 0, $deleted_calls );
	}

	public function test_relation_id_delete_locks_connection_before_metadata_mutation(): void
	{
		$connection = $this->create_connection( 'delete-lock', 'value' );
		$query = new ConnectionQuery();
		$query->set( 'id', $connection->id );
		$queries = [];
		$recorder = static function ( string $sql ) use ( &$queries ): string {
			$queries[] = trim( $sql );
			return $sql;
		};
		add_filter( 'query', $recorder );

		try {
			self::assertSame(
				1,
				$this->client->getRelation( self::RELATION )->detachConnections( $query )
			);
		} finally {
			remove_filter( 'query', $recorder );
		}

		$lock_index = $this->query_index(
			$queries,
			'/^SELECT `ID` FROM .* WHERE `ID` IN \(.+\) FOR UPDATE$/i'
		);
		$meta_delete_index = $this->query_index(
			$queries,
			'/^DELETE FROM .* WHERE `connection_id` IN \(.+\)$/i'
		);
		self::assertLessThan( $meta_delete_index, $lock_index );
	}

	public function test_create_success_hooks_run_in_order_after_commit(): void
	{
		$timeline = [];
		$query_filter = static function ( string $query ) use ( &$timeline ): string {
			if ( 1 === preg_match( '/^COMMIT$/i', trim( $query ) ) ) {
				$timeline[] = 'commit';
			}

			return $query;
		};
		$meta_after = static function () use ( &$timeline ): void {
			$timeline[] = 'meta-after';
		};
		$created = static function () use ( &$timeline ): void {
			$timeline[] = 'relation-created';
		};
		add_filter( 'query', $query_filter );
		add_action( 'wpConnections/storage/addConnectionMeta/after', $meta_after );
		add_action( 'wpConnections/relation/created', $created );

		try {
			$this->create_connection( 'hook-order', 'value' );
		} finally {
			remove_filter( 'query', $query_filter );
			remove_action( 'wpConnections/storage/addConnectionMeta/after', $meta_after );
			remove_action( 'wpConnections/relation/created', $created );
		}

		self::assertSame( [ 'commit', 'meta-after', 'relation-created' ], $timeline );
	}

	public function test_compound_domain_create_rejects_incapable_adapter_before_write(): void
	{
		AtomicMutationIncapableStorage::$create_calls = 0;
		$storage_filter = static function (): string {
			return AtomicMutationIncapableStorage::class;
		};
		add_filter( 'wpConnections/factory/getStorage/class', $storage_filter );

		try {
			$client = new Client( 'atomic-incapable-' . substr( hash( 'sha256', $this->getName() ), 0, 12 ) );
			$this->clients[] = $client;
			$this->register_relation( $client );
		} finally {
			remove_filter( 'wpConnections/factory/getStorage/class', $storage_filter );
		}

		$query = new ConnectionQuery( $this->post_ids['from'], $this->post_ids['to'] );
		$query->meta->add( new QueryMeta( 'requires', 'atomicity' ) );
		$failure = null;
		try {
			$client->getRelation( self::RELATION )->createConnection( $query );
		} catch ( Throwable $exception ) {
			$failure = $exception;
		}

		self::assertInstanceOf( StorageCapabilityUnavailable::class, $failure );
		self::assertSame( 0, AtomicMutationIncapableStorage::$create_calls );
	}

	private function register_relation( Client $client ): void
	{
		$relation = new RelationQuery();
		$relation->set( 'name', self::RELATION );
		$relation->set( 'from', 'page' );
		$relation->set( 'to', 'post' );
		$relation->set( 'cardinality', 'm-m' );
		$client->registerRelation( $relation );
	}

	private function connection_query_with_meta( string $key, string $value ): ConnectionQuery
	{
		$query = new ConnectionQuery( $this->post_ids['from'], $this->post_ids['to'] );
		$query->set( 'title', 'Atomic original' );
		$query->meta->add( new QueryMeta( $key, $value ) );

		return $query;
	}

	private function create_connection( string $key, string $value ): Connection
	{
		return $this->client->getRelation( self::RELATION )->createConnection(
			$this->connection_query_with_meta( $key, $value )
		);
	}

	private function find_connection( int $connection_id ): Connection
	{
		$query = new ConnectionQuery();
		$query->set( 'id', $connection_id );

		return $this->client->getRelation( self::RELATION )->findConnections( $query )->first();
	}

	private function capture_database_failure( string $query_fragment, callable $operation ): ?Throwable
	{
		global $wpdb;

		$intercepted = false;
		$seen_queries = [];
		$query_filter = static function ( string $query ) use ( $query_fragment, &$intercepted, &$seen_queries ): string {
			$seen_queries[] = $query;
			if ( ! $intercepted && false !== strpos( $query, $query_fragment ) ) {
				$intercepted = true;
				return 'SELECT * FROM `wpconnections_batch14_forced_atomic_failure`';
			}

			return $query;
		};

		$failure = null;
		$suppress = $wpdb->suppress_errors();
		add_filter( 'query', $query_filter );
		try {
			$operation();
		} catch ( Throwable $exception ) {
			$failure = $exception;
		} finally {
			remove_filter( 'query', $query_filter );
			$wpdb->suppress_errors( $suppress );
		}

		self::assertTrue(
			$intercepted,
			"Expected to intercept query containing {$query_fragment}. Saw:\n" . implode( "\n", $seen_queries )
		);

		return $failure;
	}

	private function connection_count( int $connection_id = 0 ): int
	{
		global $wpdb;

		$where = 0 === $connection_id ? '' : $wpdb->prepare( ' WHERE ID = %d', $connection_id );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->connections_table()}{$where}" );
	}

	private function meta_count( int $connection_id = 0 ): int
	{
		global $wpdb;

		$where = 0 === $connection_id ? '' : $wpdb->prepare( ' WHERE connection_id = %d', $connection_id );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->meta_table()}{$where}" );
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

	private function cleanup_client( Client $client ): void
	{
		global $wpdb;

		$client->disablePostDeletionCleanup();
		RestRouteRegistry::instance()->deactivateClient( $client );
		if (! $client->getStorage() instanceof WPStorage ) {
			return;
		}

		$postfix = Database::normalize_table_name( $client->getName() );
		foreach (
			[
				WPStorage::META_TABLE_PREFIX . $postfix,
				WPStorage::CONNECTIONS_TABLE_PREFIX . $postfix,
			] as $table_key
		) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$table_key}`" );
			unset( $wpdb->{$table_key} );
		}

		delete_option(
			'wpconnections_storage_owner_' . hash( 'sha256', str_replace( '-', '_', $client->getName() ) )
		);
	}

	private function query_index( array $queries, string $pattern ): int
	{
		foreach ( $queries as $index => $query ) {
			if ( 1 === preg_match( $pattern, $query ) ) {
				return $index;
			}
		}

		self::fail( "No query matched {$pattern}." );
	}
}
