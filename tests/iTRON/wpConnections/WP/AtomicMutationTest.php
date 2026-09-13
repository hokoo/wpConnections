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

	public function test_create_second_meta_failure_rolls_back_connection_and_first_meta(): void
	{
		$query = $this->connection_query_with_meta( 'first-create-meta', 'first-value' );
		$query->meta->add( new QueryMeta( 'second-create-meta', 'second-value' ) );

		$failure = $this->capture_database_failure(
			"INSERT INTO `{$this->meta_table()}`",
			function () use ( $query ): void {
				$this->client->getRelation( self::RELATION )->createConnection( $query );
			},
			2
		);

		self::assertInstanceOf( StorageFailure::class, $failure );
		self::assertSame( 0, $this->connection_count() );
		self::assertSame( 0, $this->meta_count() );
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

	public function test_aggregate_update_second_replacement_failure_restores_original_state(): void
	{
		$connection = $this->create_connection( 'original-second-fault', 'preserved' );
		$connection->title = 'changed before second replacement failure';
		$connection->meta = new MetaCollection();
		$connection->meta->add( new Meta( 'replacement-first', 'rolled-back' ) );
		$connection->meta->add( new Meta( 'replacement-second', 'rejected' ) );

		$failure = $this->capture_database_failure(
			"INSERT INTO `{$this->meta_table()}`",
			static function () use ( $connection ): void {
				$connection->update();
			},
			2
		);

		self::assertInstanceOf( StorageFailure::class, $failure );
		$persisted = $this->find_connection( $connection->id );
		self::assertSame( 'Atomic original', $persisted->title );
		self::assertSame(
			[ 'original-second-fault' => [ 'preserved' ] ],
			$persisted->meta->toArray()
		);
	}

	public function test_standalone_multi_meta_second_failure_rolls_back_first_insert(): void
	{
		$connection = $this->create_connection( 'existing-meta', 'preserved' );
		$meta = new MetaCollection();
		$meta->add( new Meta( 'standalone-first', 'rolled-back' ) );
		$meta->add( new Meta( 'standalone-second', 'rejected' ) );

		$failure = $this->capture_database_failure(
			"INSERT INTO `{$this->meta_table()}`",
			function () use ( $connection, $meta ): void {
				$this->client->getStorage()->addConnectionMeta( $connection->id, $meta );
			},
			2
		);

		self::assertInstanceOf( StorageFailure::class, $failure );
		self::assertSame( 1, $this->meta_count( $connection->id ) );
		self::assertSame(
			[ 'existing-meta' => [ 'preserved' ] ],
			$this->find_connection( $connection->id )->meta->toArray()
		);
	}

	public function test_caught_direct_delete_failure_rolls_back_its_child_scope(): void
	{
		$connection = $this->create_connection( 'caught-delete', 'preserved' );
		$inner_failure = null;

		$outer_failure = $this->capture_database_failure(
			"DELETE FROM {$this->connections_table()}",
			function () use ( $connection, &$inner_failure ): void {
				$this->client->runAtomically(
					function () use ( $connection, &$inner_failure ): void {
						try {
							$this->client->getStorage()->deleteSpecificConnections( $connection->id );
						} catch ( Throwable $failure ) {
							$inner_failure = $failure;
						}
					}
				);
			}
		);

		self::assertNull( $outer_failure );
		self::assertInstanceOf( StorageFailure::class, $inner_failure );
		self::assertSame( 1, $this->connection_count( $connection->id ) );
		self::assertSame( 1, $this->meta_count( $connection->id ) );
	}

	public function test_caught_direct_multi_meta_failure_rolls_back_its_child_scope(): void
	{
		$connection = $this->create_connection( 'caught-meta-existing', 'preserved' );
		$meta = new MetaCollection();
		$meta->add( new Meta( 'caught-meta-first', 'rolled-back' ) );
		$meta->add( new Meta( 'caught-meta-second', 'rejected' ) );
		$inner_failure = null;

		$outer_failure = $this->capture_database_failure(
			"INSERT INTO `{$this->meta_table()}`",
			function () use ( $connection, $meta, &$inner_failure ): void {
				$this->client->runAtomically(
					function () use ( $connection, $meta, &$inner_failure ): void {
						try {
							$this->client->getStorage()->addConnectionMeta( $connection->id, $meta );
						} catch ( Throwable $failure ) {
							$inner_failure = $failure;
						}
					}
				);
			},
			2
		);

		self::assertNull( $outer_failure );
		self::assertInstanceOf( StorageFailure::class, $inner_failure );
		self::assertSame( 1, $this->meta_count( $connection->id ) );
		self::assertSame(
			[ 'caught-meta-existing' => [ 'preserved' ] ],
			$this->find_connection( $connection->id )->meta->toArray()
		);
	}

	public function test_caught_direct_create_meta_failure_rolls_back_its_child_scope(): void
	{
		$query = $this->connection_query_with_meta( 'caught-create-first', 'rolled-back' );
		$query->meta->add( new QueryMeta( 'caught-create-second', 'rejected' ) );
		$query->set( 'relation', self::RELATION );
		$inner_failure = null;

		$outer_failure = $this->capture_database_failure(
			"INSERT INTO `{$this->meta_table()}`",
			function () use ( $query, &$inner_failure ): void {
				$this->client->runAtomically(
					function () use ( $query, &$inner_failure ): void {
						try {
							$this->client->getStorage()->createConnection( $query );
						} catch ( Throwable $failure ) {
							$inner_failure = $failure;
						}
					}
				);
			},
			2
		);

		self::assertNull( $outer_failure );
		self::assertInstanceOf( StorageFailure::class, $inner_failure );
		self::assertSame( 0, $this->connection_count() );
		self::assertSame( 0, $this->meta_count() );
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

	/**
	 * @dataProvider delete_fault_provider
	 */
	public function test_delete_fault_is_attributable_and_rolls_back_every_selector(
		string $entrypoint,
		string $selector,
		string $stage
	): void {
		$connection = $this->create_connection(
			"delete-{$entrypoint}-{$selector}-{$stage}",
			'preserved'
		);
		$before = $this->storage_snapshot();
		$global_success_calls = 0;
		$client_success_calls = 0;
		$success_hook = $this->delete_success_hook( $selector );
		$client_success_hook = sprintf(
			'wpConnections/client/%s/storage/%s',
			$this->client->getName(),
			$success_hook
		);
		$global_success = static function () use ( &$global_success_calls ): void {
			$global_success_calls++;
		};
		$client_success = static function () use ( &$client_success_calls ): void {
			$client_success_calls++;
		};
		add_action( "wpConnections/storage/{$success_hook}", $global_success );
		add_action( $client_success_hook, $client_success );

		try {
			$failure = $this->capture_query_failure(
				$this->delete_fault_matcher( $selector, $stage ),
				function () use ( $entrypoint, $selector, $connection ): void {
					$this->invoke_delete( $entrypoint, $selector, $connection );
				},
				'selector' === $stage
			);
		} finally {
			remove_action( "wpConnections/storage/{$success_hook}", $global_success );
			remove_action( $client_success_hook, $client_success );
		}

		$this->assert_storage_failure( $this->delete_failure_operation( $selector, $stage ), $failure );
		self::assertSame( $before, $this->storage_snapshot() );
		self::assertSame( 0, $global_success_calls );
		self::assertSame( 0, $client_success_calls );
	}

	public function delete_fault_provider(): array
	{
		$cases = [];
		foreach ( [ 'direct', 'relation' ] as $entrypoint ) {
			foreach ( [ 'id', 'pair', 'both', 'from', 'to' ] as $selector ) {
				foreach ( [ 'selector', 'meta', 'connection' ] as $stage ) {
					$cases[ "{$entrypoint}-{$selector}-{$stage}" ] = [
						$entrypoint,
						$selector,
						$stage,
					];
				}
			}
		}

		return $cases;
	}

	/**
	 * @dataProvider relation_id_lookup_fault_provider
	 */
	public function test_relation_id_lookup_failure_is_not_reported_as_no_match( bool $silent ): void
	{
		$connection = $this->create_connection(
			$silent ? 'delete-id-lookup-silent' : 'delete-id-lookup-database',
			'preserved'
		);
		$before = $this->storage_snapshot();

		$failure = $this->capture_query_failure(
			static function ( string $query ): bool {
				return 1 === preg_match(
					'/^SELECT c\.\*, m\.\* FROM .* LEFT JOIN .* WHERE c\.ID = /i',
					trim( $query )
				);
			},
			function () use ( $connection ): void {
				$this->invoke_delete( 'relation', 'id', $connection );
			},
			$silent
		);

		$this->assert_storage_failure( 'find connections', $failure );
		self::assertSame( $before, $this->storage_snapshot() );
	}

	public function relation_id_lookup_fault_provider(): array
	{
		return [
			'database-error' => [ false ],
			'silent-false'   => [ true ],
		];
	}

	public function test_direct_id_no_match_does_not_delete_orphan_metadata(): void
	{
		$missing_id = PHP_INT_MAX;
		$this->insert_orphan_meta( $missing_id, 'orphan', 'preserved' );
		$delete_queries = [];
		$success_calls = 0;
		$query_filter = static function ( string $query ) use ( &$delete_queries ): string {
			if ( 1 === preg_match( '/^DELETE FROM /i', trim( $query ) ) ) {
				$delete_queries[] = trim( $query );
			}

			return $query;
		};
		$success = static function () use ( &$success_calls ): void {
			$success_calls++;
		};
		add_filter( 'query', $query_filter );
		add_action( 'wpConnections/storage/deletedSpecificConnections', $success );

		try {
			self::assertSame(
				0,
				$this->client->getStorage()->deleteSpecificConnections( $missing_id )
			);
		} finally {
			remove_filter( 'query', $query_filter );
			remove_action( 'wpConnections/storage/deletedSpecificConnections', $success );
		}

		self::assertSame( 1, $this->meta_count( $missing_id ) );
		self::assertSame( [], $delete_queries );
		self::assertSame( 0, $success_calls );
	}

	public function test_direct_id_partial_match_writes_only_locked_connection_ids(): void
	{
		$connection = $this->create_connection( 'partial-match', 'deleted' );
		$missing_id = $connection->id + 1000000;
		$this->insert_orphan_meta( $missing_id, 'orphan', 'preserved' );

		self::assertSame(
			1,
			$this->client->getStorage()->deleteSpecificConnections(
				[ $connection->id, $missing_id ]
			)
		);

		self::assertSame( 0, $this->connection_count( $connection->id ) );
		self::assertSame( 0, $this->meta_count( $connection->id ) );
		self::assertSame( 1, $this->meta_count( $missing_id ) );
	}

	/**
	 * @dataProvider delete_entrypoint_selector_provider
	 */
	public function test_delete_no_match_has_no_dml_or_success_hook(
		string $entrypoint,
		string $selector
	): void {
		$this->create_connection( "no-match-control-{$entrypoint}-{$selector}", 'preserved' );
		$before = $this->storage_snapshot();
		$delete_queries = [];
		$global_success_calls = 0;
		$client_success_calls = 0;
		$success_hook = $this->delete_success_hook( $selector );
		$client_success_hook = sprintf(
			'wpConnections/client/%s/storage/%s',
			$this->client->getName(),
			$success_hook
		);
		$query_filter = static function ( string $query ) use ( &$delete_queries ): string {
			if ( 1 === preg_match( '/^DELETE FROM /i', trim( $query ) ) ) {
				$delete_queries[] = trim( $query );
			}

			return $query;
		};
		$global_success = static function () use ( &$global_success_calls ): void {
			$global_success_calls++;
		};
		$client_success = static function () use ( &$client_success_calls ): void {
			$client_success_calls++;
		};
		add_filter( 'query', $query_filter );
		add_action( "wpConnections/storage/{$success_hook}", $global_success );
		add_action( $client_success_hook, $client_success );

		try {
			self::assertSame( 0, $this->invoke_no_match_delete( $entrypoint, $selector ) );
		} finally {
			remove_filter( 'query', $query_filter );
			remove_action( "wpConnections/storage/{$success_hook}", $global_success );
			remove_action( $client_success_hook, $client_success );
		}

		self::assertSame( $before, $this->storage_snapshot() );
		self::assertSame( [], $delete_queries );
		self::assertSame( 0, $global_success_calls );
		self::assertSame( 0, $client_success_calls );
	}

	/**
	 * @dataProvider delete_entrypoint_selector_provider
	 */
	public function test_delete_hooks_preserve_arguments_and_run_after_owning_commit(
		string $entrypoint,
		string $selector
	): void {
		$connection = $this->create_connection(
			"delete-hooks-{$entrypoint}-{$selector}",
			'deleted'
		);
		$timeline = [];
		$attempt_global_arguments = [];
		$attempt_client_arguments = [];
		$success_global_arguments = [];
		$success_client_arguments = [];
		$attempt_hook = $this->delete_attempt_hook( $selector );
		$success_hook = $this->delete_success_hook( $selector );
		$client_attempt_hook = sprintf(
			'wpConnections/client/%s/storage/%s',
			$this->client->getName(),
			$attempt_hook
		);
		$client_success_hook = sprintf(
			'wpConnections/client/%s/storage/%s',
			$this->client->getName(),
			$success_hook
		);
		$query_filter = static function ( string $query ) use ( &$timeline ): string {
			$query = trim( $query );
			if ( 1 === preg_match( '/^START TRANSACTION$/i', $query ) ) {
				$timeline[] = 'start';
			} elseif ( 1 === preg_match( '/^SAVEPOINT /i', $query ) ) {
				$timeline[] = 'savepoint';
			} elseif ( 1 === preg_match( '/^RELEASE SAVEPOINT /i', $query ) ) {
				$timeline[] = 'release';
			} elseif ( 1 === preg_match( '/^COMMIT$/i', $query ) ) {
				$timeline[] = 'commit';
			}

			return $query;
		};
		$attempt_global = static function ( ...$arguments ) use (
			&$timeline,
			&$attempt_global_arguments
		): void {
			$timeline[] = 'attempt-global';
			$attempt_global_arguments = $arguments;
		};
		$attempt_client = static function ( ...$arguments ) use (
			&$timeline,
			&$attempt_client_arguments
		): void {
			$timeline[] = 'attempt-client';
			$attempt_client_arguments = $arguments;
		};
		$success_global = static function ( ...$arguments ) use (
			&$timeline,
			&$success_global_arguments
		): void {
			$timeline[] = 'success-global';
			$success_global_arguments = $arguments;
		};
		$success_client = static function ( ...$arguments ) use (
			&$timeline,
			&$success_client_arguments
		): void {
			$timeline[] = 'success-client';
			$success_client_arguments = $arguments;
		};
		add_filter( 'query', $query_filter );
		add_action( "wpConnections/storage/{$attempt_hook}", $attempt_global, 10, 10 );
		add_action( $client_attempt_hook, $attempt_client, 10, 10 );
		add_action( "wpConnections/storage/{$success_hook}", $success_global, 10, 10 );
		add_action( $client_success_hook, $success_client, 10, 10 );

		try {
			self::assertSame( 1, $this->invoke_delete( $entrypoint, $selector, $connection ) );
		} finally {
			remove_filter( 'query', $query_filter );
			remove_action( "wpConnections/storage/{$attempt_hook}", $attempt_global );
			remove_action( $client_attempt_hook, $attempt_client );
			remove_action( "wpConnections/storage/{$success_hook}", $success_global );
			remove_action( $client_success_hook, $success_client );
		}

		self::assertSame(
			$this->expected_delete_timeline( $entrypoint ),
			$timeline
		);
		self::assertSame(
			$this->expected_delete_attempt_arguments( $selector, $connection, true ),
			$attempt_global_arguments
		);
		self::assertSame(
			$this->expected_delete_attempt_arguments( $selector, $connection, false ),
			$attempt_client_arguments
		);
		self::assertSame(
			$this->expected_delete_success_arguments( $selector, $connection, true ),
			$success_global_arguments
		);
		self::assertSame(
			$this->expected_delete_success_arguments( $selector, $connection, false ),
			$success_client_arguments
		);
		self::assertSame( 0, $this->connection_count( $connection->id ) );
		self::assertSame( 0, $this->meta_count( $connection->id ) );
	}

	public function delete_entrypoint_selector_provider(): array
	{
		$cases = [];
		foreach ( [ 'direct', 'relation' ] as $entrypoint ) {
			foreach ( [ 'id', 'pair', 'both', 'from', 'to' ] as $selector ) {
				$cases[ "{$entrypoint}-{$selector}" ] = [ $entrypoint, $selector ];
			}
		}

		return $cases;
	}

	public function test_delete_commit_failure_restores_rows_and_discards_success_hooks(): void
	{
		$connection = $this->create_connection( 'delete-commit-failure', 'preserved' );
		$before = $this->storage_snapshot();
		$success_calls = 0;
		$success = static function () use ( &$success_calls ): void {
			$success_calls++;
		};
		add_action( 'wpConnections/storage/deletedSpecificConnections', $success );

		try {
			$failure = $this->capture_query_failure(
				static function ( string $query ): bool {
					return 1 === preg_match( '/^COMMIT$/i', trim( $query ) );
				},
				function () use ( $connection ): void {
					$this->client->getStorage()->deleteSpecificConnections( $connection->id );
				},
				false
			);
		} finally {
			remove_action( 'wpConnections/storage/deletedSpecificConnections', $success );
		}

		$this->assert_storage_failure( 'commit transaction', $failure );
		self::assertSame( $before, $this->storage_snapshot() );
		self::assertSame( 0, $success_calls );
	}

	public function test_delete_query_filter_throwable_rolls_back_and_escapes_unchanged(): void
	{
		$connection = $this->create_connection( 'delete-query-throwable', 'preserved' );
		$before = $this->storage_snapshot();
		$expected = new \RuntimeException( 'delete query filter failure' );
		$success_calls = 0;
		$success = static function () use ( &$success_calls ): void {
			$success_calls++;
		};
		$query_filter = function ( string $query ) use ( $expected ): string {
			if ( 1 === preg_match(
				'/^DELETE FROM ' . preg_quote( $this->connections_table(), '/' ) . ' WHERE /i',
				trim( $query )
			) ) {
				throw $expected;
			}

			return $query;
		};
		add_filter( 'query', $query_filter );
		add_action( 'wpConnections/storage/deletedSpecificConnections', $success );

		$actual = null;
		try {
			$this->client->getStorage()->deleteSpecificConnections( $connection->id );
		} catch ( Throwable $failure ) {
			$actual = $failure;
		} finally {
			remove_filter( 'query', $query_filter );
			remove_action( 'wpConnections/storage/deletedSpecificConnections', $success );
		}

		self::assertSame( $expected, $actual );
		self::assertSame( $before, $this->storage_snapshot() );
		self::assertSame( 0, $success_calls );
	}

	public function test_successful_child_delete_is_restored_when_outer_scope_rolls_back(): void
	{
		$connection = $this->create_connection( 'delete-outer-rollback', 'preserved' );
		$before = $this->storage_snapshot();
		$expected = new \RuntimeException( 'outer mutation rejected after child delete' );
		$global_success_calls = 0;
		$client_success_calls = 0;
		$global_success = static function () use ( &$global_success_calls ): void {
			$global_success_calls++;
		};
		$client_success = static function () use ( &$client_success_calls ): void {
			$client_success_calls++;
		};
		$client_success_hook = sprintf(
			'wpConnections/client/%s/storage/deletedSpecificConnections',
			$this->client->getName()
		);
		add_action( 'wpConnections/storage/deletedSpecificConnections', $global_success );
		add_action( $client_success_hook, $client_success );

		$actual = null;
		try {
			$this->client->runAtomically(
				function () use ( $connection, $expected ): void {
					self::assertSame(
						1,
						$this->client->getStorage()->deleteSpecificConnections( $connection->id )
					);
					throw $expected;
				}
			);
		} catch ( Throwable $failure ) {
			$actual = $failure;
		} finally {
			remove_action( 'wpConnections/storage/deletedSpecificConnections', $global_success );
			remove_action( $client_success_hook, $client_success );
		}

		self::assertSame( $expected, $actual );
		self::assertSame( $before, $this->storage_snapshot() );
		self::assertSame( 0, $global_success_calls );
		self::assertSame( 0, $client_success_calls );
	}

	public function test_delete_post_commit_hook_throwable_preserves_durable_delete(): void
	{
		$connection = $this->create_connection( 'delete-hook-throwable', 'deleted' );
		$expected = new \RuntimeException( 'delete success hook failure' );
		$success = static function () use ( $expected ): void {
			throw $expected;
		};
		add_action( 'wpConnections/storage/deletedSpecificConnections', $success );

		$actual = null;
		try {
			$this->client->getStorage()->deleteSpecificConnections( $connection->id );
		} catch ( Throwable $failure ) {
			$actual = $failure;
		} finally {
			remove_action( 'wpConnections/storage/deletedSpecificConnections', $success );
		}

		self::assertSame( $expected, $actual );
		self::assertSame( 0, $this->connection_count( $connection->id ) );
		self::assertSame( 0, $this->meta_count( $connection->id ) );
	}

	public function test_relation_id_delete_serializes_membership_change_before_cascade(): void
	{
		$connection = $this->create_connection( 'delete-lock-target', 'deleted' );
		$other_to = wp_insert_post(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Atomic mutation unrelated target',
			]
		);
		self::assertIsInt( $other_to );
		$this->post_ids[] = $other_to;
		$unrelated_query = new ConnectionQuery( $this->post_ids['from'], $other_to );
		$unrelated_query->meta->add( new QueryMeta( 'unrelated', 'preserved' ) );
		$unrelated = $this->client->getRelation( self::RELATION )->createConnection(
			$unrelated_query
		);
		$secondary = $this->secondary_database_connection();
		$secondary->query( 'SET SESSION innodb_lock_wait_timeout = 5' );
		$membership_changed_before_cascade = null;
		$secondary_affected_rows = null;
		$attempt = function () use (
			$secondary,
			$connection,
			&$membership_changed_before_cascade,
			&$secondary_affected_rows
		): void {
			$table = str_replace( '`', '``', $this->connections_table() );
			$relation = $secondary->real_escape_string( self::RELATION . '-foreign' );
			$sql = "UPDATE `{$table}` SET `relation` = '{$relation}' WHERE `ID` = {$connection->id}";
			self::assertTrue( $secondary->query( $sql, MYSQLI_ASYNC ) );
			$membership_changed_before_cascade = $this->wait_for_async_query( $secondary, 1.0 );
			if ( $membership_changed_before_cascade ) {
				self::assertTrue( $secondary->reap_async_query() );
				$secondary_affected_rows = $secondary->affected_rows;
			}
		};
		add_action( 'wpConnections/storage/deleteSpecificConnections', $attempt );
		$query = new ConnectionQuery();
		$query->set( 'id', $connection->id );

		try {
			$deleted = $this->client->getRelation( self::RELATION )->detachConnections( $query );
			if ( ! $membership_changed_before_cascade ) {
				self::assertTrue( $this->wait_for_async_query( $secondary, 5.0 ) );
				self::assertTrue( $secondary->reap_async_query() );
				$secondary_affected_rows = $secondary->affected_rows;
			}
		} finally {
			remove_action( 'wpConnections/storage/deleteSpecificConnections', $attempt );
			$secondary->close();
		}

		self::assertFalse(
			$membership_changed_before_cascade,
			'A concurrent relation membership update completed between relation lookup and delete cascade.'
		);
		self::assertSame( 1, $deleted );
		self::assertSame( 0, $secondary_affected_rows );
		self::assertSame( 0, $this->connection_count( $connection->id ) );
		self::assertSame( 0, $this->meta_count( $connection->id ) );
		self::assertSame( 1, $this->connection_count( $unrelated->id ) );
		self::assertSame( 1, $this->meta_count( $unrelated->id ) );
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

	private function capture_database_failure(
		string $query_fragment,
		callable $operation,
		int $matching_occurrence = 1
	): ?Throwable
	{
		global $wpdb;

		$intercepted = false;
		$matches = 0;
		$seen_queries = [];
		$query_filter = static function ( string $query ) use (
			$query_fragment,
			$matching_occurrence,
			&$matches,
			&$intercepted,
			&$seen_queries
		): string {
			$seen_queries[] = $query;
			if ( false !== strpos( $query, $query_fragment ) ) {
				$matches++;
			}

			if ( ! $intercepted && $matching_occurrence === $matches ) {
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

	private function capture_query_failure(
		callable $matches_query,
		callable $operation,
		bool $silent
	): ?Throwable {
		global $wpdb;

		$intercepted = false;
		$seen_queries = [];
		$query_filter = static function ( string $query ) use (
			$matches_query,
			$silent,
			&$intercepted,
			&$seen_queries,
			$wpdb
		): string {
			$seen_queries[] = $query;
			if ( ! $intercepted && $matches_query( $query ) ) {
				$intercepted = true;
				if ( $silent ) {
					$wpdb->last_error = '';
					return '';
				}

				return 'SELECT * FROM `wpconnections_batch15_forced_delete_failure`';
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
			"Expected to intercept a delete query. Saw:\n" . implode( "\n", $seen_queries )
		);

		return $failure;
	}

	private function invoke_delete( string $entrypoint, string $selector, Connection $connection ): int
	{
		if ( 'direct' === $entrypoint ) {
			switch ( $selector ) {
				case 'id':
					return $this->client->getStorage()->deleteSpecificConnections( $connection->id );
				case 'pair':
					return $this->client->getStorage()->deleteDirectedConnections(
						$connection->from,
						$connection->to,
						self::RELATION
					);
				case 'both':
					return $this->client->getStorage()->deleteByObjectID( $connection->from, self::RELATION );
				case 'from':
					return $this->client->getStorage()->deleteByObjectID(
						$connection->from,
						self::RELATION,
						true
					);
				case 'to':
					return $this->client->getStorage()->deleteByObjectID(
						$connection->to,
						self::RELATION,
						false,
						true
					);
			}
		}

		$query = new ConnectionQuery();
		switch ( $selector ) {
			case 'id':
				$query->set( 'id', $connection->id );
				break;
			case 'pair':
				$query->set( 'from', $connection->from );
				$query->set( 'to', $connection->to );
				break;
			case 'both':
				$query->set( 'both', $connection->from );
				break;
			case 'from':
				$query->set( 'from', $connection->from );
				break;
			case 'to':
				$query->set( 'to', $connection->to );
				break;
		}

		return $this->client->getRelation( self::RELATION )->detachConnections( $query );
	}

	private function invoke_no_match_delete( string $entrypoint, string $selector ): int
	{
		$first = PHP_INT_MAX - 1;
		$second = PHP_INT_MAX;

		if ( 'direct' === $entrypoint ) {
			switch ( $selector ) {
				case 'id':
					return $this->client->getStorage()->deleteSpecificConnections( $first );
				case 'pair':
					return $this->client->getStorage()->deleteDirectedConnections(
						$first,
						$second,
						self::RELATION
					);
				case 'both':
					return $this->client->getStorage()->deleteByObjectID( $first, self::RELATION );
				case 'from':
					return $this->client->getStorage()->deleteByObjectID( $first, self::RELATION, true );
				case 'to':
					return $this->client->getStorage()->deleteByObjectID(
						$first,
						self::RELATION,
						false,
						true
					);
			}
		}

		$query = new ConnectionQuery();
		switch ( $selector ) {
			case 'id':
				$query->set( 'id', $first );
				break;
			case 'pair':
				$query->set( 'from', $first );
				$query->set( 'to', $second );
				break;
			case 'both':
				$query->set( 'both', $first );
				break;
			case 'from':
				$query->set( 'from', $first );
				break;
			case 'to':
				$query->set( 'to', $first );
				break;
		}

		return $this->client->getRelation( self::RELATION )->detachConnections( $query );
	}

	private function delete_attempt_hook( string $selector ): string
	{
		if ( 'id' === $selector ) {
			return 'deleteSpecificConnections';
		}

		return 'pair' === $selector ? 'deleteDirectedConnections' : 'deleteByObjectID';
	}

	private function expected_delete_timeline( string $entrypoint ): array
	{
		if ( 'direct' === $entrypoint ) {
			return [
				'attempt-global',
				'attempt-client',
				'start',
				'commit',
				'success-global',
				'success-client',
			];
		}

		return [
			'start',
			'attempt-global',
			'attempt-client',
			'savepoint',
			'release',
			'commit',
			'success-global',
			'success-client',
		];
	}

	private function expected_delete_attempt_arguments(
		string $selector,
		Connection $connection,
		bool $global
	): array {
		$arguments = [];
		if ( $global ) {
			$arguments[] = $this->client;
		}

		if ( 'id' === $selector ) {
			$arguments[] = $connection->id;
			return $arguments;
		}

		if ( 'pair' === $selector ) {
			array_push( $arguments, $connection->from, $connection->to, self::RELATION );
			return $arguments;
		}

		$arguments[] = 'to' === $selector ? $connection->to : $connection->from;
		$arguments[] = self::RELATION;
		$arguments[] = 'from' === $selector;
		$arguments[] = 'to' === $selector;

		return $arguments;
	}

	private function expected_delete_success_arguments(
		string $selector,
		Connection $connection,
		bool $global
	): array {
		$arguments = [];
		if ( $global ) {
			$arguments[] = $this->client;
		}

		$arguments[] = [ $connection->id ];
		if ( 'id' === $selector ) {
			$arguments[] = 1;
		}

		return $arguments;
	}

	private function delete_fault_matcher( string $selector, string $stage ): callable
	{
		if ( 'meta' === $stage ) {
			return function ( string $query ): bool {
				return 1 === preg_match(
					'/^DELETE FROM ' . preg_quote( $this->meta_table(), '/' ) . ' WHERE /i',
					trim( $query )
				);
			};
		}

		if ( 'connection' === $stage ) {
			return function ( string $query ): bool {
				return 1 === preg_match(
					'/^DELETE FROM ' . preg_quote( $this->connections_table(), '/' ) . ' WHERE /i',
					trim( $query )
				);
			};
		}

		return function ( string $query ) use ( $selector ): bool {
			$query = trim( $query );
			if ( 1 !== preg_match( '/^SELECT `ID` FROM .* FOR UPDATE$/i', $query ) ) {
				return false;
			}

			if ( 'id' === $selector ) {
				return false !== strpos( $query, 'WHERE `ID` IN' );
			}

			if ( 'pair' === $selector ) {
				return false !== strpos( $query, 'AND `from` =' )
					&& false !== strpos( $query, 'AND `to` =' );
			}

			return false !== strpos( $query, ' IN (' );
		};
	}

	private function delete_failure_operation( string $selector, string $stage ): string
	{
		if ( 'selector' === $stage ) {
			return 'id' === $selector ? 'lock connections for delete' : 'select connections for delete';
		}

		return 'meta' === $stage ? 'delete connection metadata' : 'delete connections';
	}

	private function delete_success_hook( string $selector ): string
	{
		if ( 'id' === $selector ) {
			return 'deletedSpecificConnections';
		}

		return 'pair' === $selector ? 'deletedDirectedConnections' : 'deletedByObjectID';
	}

	private function assert_storage_failure( string $operation, ?Throwable $failure ): void
	{
		self::assertInstanceOf( StorageFailure::class, $failure );
		self::assertSame( StorageFailure::CODE, $failure->getCode() );
		self::assertSame( $operation, $failure->getOperation() );
		self::assertSame( "Storage operation failed: {$operation}.", $failure->getMessage() );
		self::assertInstanceOf( \RuntimeException::class, $failure->getPrevious() );
	}

	private function storage_snapshot(): array
	{
		global $wpdb;

		return [
			'connections' => $wpdb->get_results(
				"SELECT * FROM {$this->connections_table()} ORDER BY `ID`",
				ARRAY_A
			),
			'meta'        => $wpdb->get_results(
				"SELECT * FROM {$this->meta_table()} ORDER BY `meta_id`",
				ARRAY_A
			),
		];
	}

	private function insert_orphan_meta( int $connection_id, string $key, string $value ): void
	{
		global $wpdb;

		self::assertSame(
			1,
			$wpdb->insert(
				$this->meta_table(),
				[
					'connection_id' => $connection_id,
					'meta_key'      => $key,
					'meta_value'    => $value,
				],
				[ '%d', '%s', '%s' ]
			)
		);
	}

	private function secondary_database_connection(): \mysqli
	{
		$host = getenv( 'DB_HOST' ) ?: '127.0.0.1';
		$user = getenv( 'DB_USER' ) ?: 'wordpress';
		$password = getenv( 'DB_PASSWORD' ) ?: 'wordpress';
		$database = getenv( 'DB_NAME' ) ?: 'wordpress_test';
		$port = (int) ( getenv( 'DB_PORT' ) ?: 3306 );
		$connection = new \mysqli( $host, $user, $password, $database, $port );
		$connection->set_charset( 'utf8mb4' );

		return $connection;
	}

	private function wait_for_async_query( \mysqli $connection, float $timeout ): bool
	{
		$read = [ $connection ];
		$error = [ $connection ];
		$reject = [ $connection ];
		$seconds = (int) $timeout;
		$microseconds = (int) ( ( $timeout - $seconds ) * 1000000 );

		return 0 < \mysqli_poll( $read, $error, $reject, $seconds, $microseconds );
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
