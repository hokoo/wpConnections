<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Abstracts\Connection as AbstractConnection;
use iTRON\wpConnections\Abstracts\Storage;
use iTRON\wpConnections\Client;
use iTRON\wpConnections\ConnectionCollection;
use iTRON\wpConnections\Exceptions\StorageCapabilityUnavailable;
use iTRON\wpConnections\Exceptions\StorageFailure;
use iTRON\wpConnections\Helpers\Database;
use iTRON\wpConnections\Internal\RestRouteRegistry;
use iTRON\wpConnections\MetaCollection;
use iTRON\wpConnections\Query\Connection as ConnectionQuery;
use iTRON\wpConnections\Query\MetaCollection as MetaQueryCollection;
use iTRON\wpConnections\Query\Relation as RelationQuery;
use iTRON\wpConnections\TransactionContext;
use iTRON\wpConnections\TransactionSynchronizer;
use iTRON\wpConnections\WPStorage;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

class IncapableAtomicScopeStorage extends Storage
{
	public function createConnection( ConnectionQuery $connection_query ): int
	{
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

class AtomicScopeTest extends TestCase
{
	private Client $client;
	private array $clients = [];
	private array $wpdb_tables_before = [];

	protected function setUp(): void
	{
		parent::setUp();

		global $wpdb;
		$this->wpdb_tables_before = $wpdb->tables;
		add_filter( 'wpConnections/storage/installOnInit', '__return_true', 10, 2 );

		$name = 'atomic-scope-' . substr( hash( 'sha256', $this->getName() ), 0, 12 );
		$this->client = new Client( $name );
		$this->clients[] = $this->client;
		$this->register_relation();
	}

	protected function tearDown(): void
	{
		global $wpdb;

		try {
			$wpdb->query( 'ROLLBACK' );
			foreach ( array_reverse( $this->clients ) as $client ) {
				$this->cleanup_client( $client );
			}
			$wpdb->tables = $this->wpdb_tables_before;
			remove_filter( 'wpConnections/storage/installOnInit', '__return_true', 10 );
		} finally {
			parent::tearDown();
		}
	}

	public function test_root_scope_commits_once_and_returns_callback_result(): void
	{
		$queries = [];
		$recorder = static function ( string $query ) use ( &$queries ): string {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $recorder );

		try {
			$result = $this->client->runAtomically(
				static function (): string {
					return 'callback-result';
				},
				TransactionContext::root()
			);
		} finally {
			remove_filter( 'query', $recorder );
		}

		self::assertSame( 'callback-result', $result );
		self::assertSame( 1, $this->query_count( $queries, '/^START TRANSACTION$/i' ) );
		self::assertSame( 1, $this->query_count( $queries, '/^COMMIT$/i' ) );
		self::assertSame( 0, $this->query_count( $queries, '/^ROLLBACK(?:\s|$)/i' ) );
		$start_index = $this->first_query_index( $queries, '/^START TRANSACTION$/i' );
		self::assertLessThan(
			$start_index,
			$this->first_query_index( $queries, '/^SHOW COLUMNS FROM /i' )
		);
		self::assertLessThan(
			$this->first_query_index( $queries, '/^COMMIT$/i' ),
			$start_index
		);
		self::assertSame(
			0,
			$this->query_count( array_slice( $queries, $start_index ), '/^(?:CREATE|ALTER|DROP)\s/i' )
		);
	}

	public function test_false_callback_result_is_committed_and_preserved(): void
	{
		$queries = [];
		$recorder = static function ( string $query ) use ( &$queries ): string {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $recorder );

		try {
			$result = $this->client->runAtomically( '__return_false' );
		} finally {
			remove_filter( 'query', $recorder );
		}

		self::assertFalse( $result );
		self::assertSame( 1, $this->query_count( $queries, '/^COMMIT$/i' ) );
		self::assertSame( 0, $this->query_count( $queries, '/^ROLLBACK(?:\s|$)/i' ) );
	}

	public function test_callback_throwable_rolls_back_and_remains_identical(): void
	{
		$queries = [];
		$expected = new RuntimeException( 'atomic callback failed' );
		$recorder = static function ( string $query ) use ( &$queries ): string {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $recorder );

		$caught = null;
		try {
			$this->client->runAtomically(
				static function () use ( $expected ): void {
					throw $expected;
				}
			);
		} catch ( Throwable $failure ) {
			$caught = $failure;
		} finally {
			remove_filter( 'query', $recorder );
		}

		self::assertSame( $expected, $caught );
		self::assertSame( 0, $this->query_count( $queries, '/^COMMIT$/i' ) );
		self::assertSame( 1, $this->query_count( $queries, '/^ROLLBACK$/i' ) );
	}

	public function test_start_failure_prevents_callback_and_uses_storage_failure(): void
	{
		$callback_called = false;
		$intercepted = false;
		$filter = static function ( string $query ) use ( &$intercepted ): string {
			if ( ! $intercepted && preg_match( '/^START TRANSACTION$/i', $query ) ) {
				$intercepted = true;
				return 'SELECT * FROM `wpconnections_batch14_failed_start`';
			}

			return $query;
		};

		global $wpdb;
		$suppress = $wpdb->suppress_errors();
		add_filter( 'query', $filter );
		$failure = null;
		try {
			$this->client->runAtomically(
				static function () use ( &$callback_called ): void {
					$callback_called = true;
				}
			);
		} catch ( Throwable $exception ) {
			$failure = $exception;
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $suppress );
		}

		self::assertTrue( $intercepted );
		self::assertFalse( $callback_called );
		self::assertInstanceOf( StorageFailure::class, $failure );
		self::assertSame( 'start transaction', $failure->getOperation() );
	}

	public function test_incapable_storage_rejects_scope_before_callback(): void
	{
		$callback_called = false;
		$this->client->disablePostDeletionCleanup();
		$property = new \ReflectionProperty( Client::class, 'storage' );
		$property->setAccessible( true );
		$property->setValue( $this->client, new IncapableAtomicScopeStorage() );

		try {
			$this->client->runAtomically(
				static function () use ( &$callback_called ): void {
					$callback_called = true;
				}
			);
			self::fail( 'An incapable storage must reject the atomic scope.' );
		} catch ( StorageCapabilityUnavailable $failure ) {
			self::assertSame( StorageCapabilityUnavailable::CODE, $failure->getCode() );
		}

		self::assertFalse( $callback_called );
	}

	public function test_cross_client_reentry_is_rejected_before_inner_callback(): void
	{
		$inner_callback_called = false;
		$second = new Client( 'atomic-peer-' . substr( hash( 'sha256', $this->getName() ), 0, 12 ) );
		$this->clients[] = $second;

		try {
			$this->client->runAtomically(
				static function () use ( $second, &$inner_callback_called ): void {
					$second->runAtomically(
						static function () use ( &$inner_callback_called ): void {
							$inner_callback_called = true;
						}
					);
				}
			);
			self::fail( 'Cross-client re-entry must be rejected.' );
		} catch ( StorageCapabilityUnavailable $failure ) {
			self::assertSame( StorageCapabilityUnavailable::CODE, $failure->getCode() );
		}

		self::assertFalse( $inner_callback_called );
	}

	public function test_commit_failure_is_normalized_after_callback(): void
	{
		$callback_called = false;
		$intercepted = false;
		$filter = static function ( string $query ) use ( &$intercepted ): string {
			if ( ! $intercepted && preg_match( '/^COMMIT$/i', $query ) ) {
				$intercepted = true;
				return 'SELECT * FROM `wpconnections_batch14_failed_commit`';
			}

			return $query;
		};

		global $wpdb;
		$suppress = $wpdb->suppress_errors();
		add_filter( 'query', $filter );
		$failure = null;
		try {
			$this->client->runAtomically(
				static function () use ( &$callback_called ): void {
					$callback_called = true;
				}
			);
		} catch ( Throwable $exception ) {
			$failure = $exception;
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $suppress );
		}

		self::assertTrue( $intercepted );
		self::assertTrue( $callback_called );
		self::assertInstanceOf( StorageFailure::class, $failure );
		self::assertSame( 'commit transaction', $failure->getOperation() );
	}

	public function test_failed_rollback_after_commit_failure_is_not_silently_ignored(): void
	{
		$failed = [];
		$filter = static function ( string $query ) use ( &$failed ): string {
			$normalized = trim( $query );
			if ( preg_match( '/^(?:COMMIT|ROLLBACK)$/i', $normalized ) ) {
				$failed[] = $normalized;
				return 'SELECT * FROM `wpconnections_batch14_failed_commit_recovery`';
			}

			return $query;
		};

		global $wpdb;
		$suppress = $wpdb->suppress_errors();
		add_filter( 'query', $filter );
		$failure = null;
		try {
			$this->client->runAtomically( '__return_true' );
		} catch ( Throwable $exception ) {
			$failure = $exception;
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $suppress );
		}

		self::assertSame( [ 'COMMIT', 'ROLLBACK' ], $failed );
		self::assertInstanceOf( StorageFailure::class, $failure );
		self::assertSame( 'rollback transaction after commit failure', $failure->getOperation() );
		self::assertInstanceOf( StorageFailure::class, $failure->getPrevious()->getPrevious() );
		self::assertSame(
			'commit transaction',
			$failure->getPrevious()->getPrevious()->getOperation()
		);
	}

	public function test_nested_scopes_use_unique_savepoints_without_completing_outer_transaction(): void
	{
		global $wpdb;

		self::assertNotFalse( $wpdb->query( 'START TRANSACTION' ) );
		$first_id = $this->insert_connection_row( 'nested-first' );
		$queries = [];
		$recorder = static function ( string $query ) use ( &$queries ): string {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $recorder );
		$first_sync = new TransactionSynchronizer();
		$second_sync = new TransactionSynchronizer();

		try {
			$first_result = $this->client->runAtomically(
				static function (): string {
					return 'first-result';
				},
				TransactionContext::nested( $first_sync )
			);
			$second_result = $this->client->runAtomically(
				static function (): string {
					return 'second-result';
				},
				TransactionContext::nested( $second_sync )
			);
		} finally {
			remove_filter( 'query', $recorder );
		}

		self::assertSame( 'first-result', $first_result );
		self::assertSame( 'second-result', $second_result );
		self::assertSame( 0, $this->query_count( $queries, '/^(?:START TRANSACTION|COMMIT)$/i' ) );
		$savepoints = $this->savepoint_names( $queries, '/^SAVEPOINT ([a-z0-9_]+)$/i' );
		$releases = $this->savepoint_names( $queries, '/^RELEASE SAVEPOINT ([a-z0-9_]+)$/i' );
		self::assertCount( 2, $savepoints );
		self::assertCount( 2, array_unique( $savepoints ) );
		self::assertSame( $savepoints, $releases );

		self::assertNotFalse( $wpdb->query( 'ROLLBACK' ) );
		$first_sync->rolledBack();
		$second_sync->rolledBack();
		self::assertFalse( $this->connection_row_exists( $first_id ) );
	}

	public function test_nested_failure_rolls_back_to_savepoint_and_preserves_outer_transaction(): void
	{
		global $wpdb;

		self::assertNotFalse( $wpdb->query( 'START TRANSACTION' ) );
		$outer_id = $this->insert_connection_row( 'outer-before-savepoint' );
		$inner_id = 0;
		$expected = new RuntimeException( 'nested callback failed' );
		$timeline = [];
		$synchronizer = new TransactionSynchronizer();
		$queries = [];
		$recorder = static function ( string $query ) use ( &$queries ): string {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $recorder );

		$caught = null;
		try {
			$this->client->runAtomically(
				function () use ( &$inner_id, &$timeline, $expected ): void {
					$inner_id = $this->insert_connection_row( 'inside-failed-savepoint' );
					$this->client->deferSuccessNotification(
						static function () use ( &$timeline ): void {
							$timeline[] = 'must-not-run';
						}
					);
					throw $expected;
				},
				TransactionContext::nested( $synchronizer )
			);
		} catch ( Throwable $failure ) {
			$caught = $failure;
		} finally {
			remove_filter( 'query', $recorder );
		}

		self::assertSame( $expected, $caught );
		self::assertSame( 1, $this->query_count( $queries, '/^ROLLBACK TO SAVEPOINT /i' ) );
		self::assertSame( 1, $this->query_count( $queries, '/^RELEASE SAVEPOINT /i' ) );
		self::assertTrue( $this->connection_row_exists( $outer_id ) );
		self::assertFalse( $this->connection_row_exists( $inner_id ) );

		self::assertNotFalse( $wpdb->query( 'ROLLBACK' ) );
		$synchronizer->rolledBack();
		self::assertFalse( $this->connection_row_exists( $outer_id ) );
		self::assertSame( [], $timeline );
	}

	public function test_nested_failure_reports_failed_savepoint_cleanup(): void
	{
		global $wpdb;

		self::assertNotFalse( $wpdb->query( 'START TRANSACTION' ) );
		$synchronizer = new TransactionSynchronizer();
		$expected = new RuntimeException( 'nested callback requiring cleanup' );
		$release_failed = false;
		$filter = static function ( string $query ) use ( &$release_failed ): string {
			if ( ! $release_failed && preg_match( '/^RELEASE SAVEPOINT /i', trim( $query ) ) ) {
				$release_failed = true;
				return 'SELECT * FROM `wpconnections_batch14_failed_savepoint_cleanup`';
			}

			return $query;
		};

		$suppress = $wpdb->suppress_errors();
		add_filter( 'query', $filter );
		$failure = null;
		try {
			$this->client->runAtomically(
				static function () use ( $expected ): void {
					throw $expected;
				},
				TransactionContext::nested( $synchronizer )
			);
		} catch ( Throwable $exception ) {
			$failure = $exception;
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $suppress );
		}

		self::assertTrue( $release_failed );
		self::assertInstanceOf( StorageFailure::class, $failure );
		self::assertSame( 'release savepoint after rollback', $failure->getOperation() );
		self::assertSame( $expected, $failure->getPrevious()->getPrevious() );

		self::assertNotFalse( $wpdb->query( 'ROLLBACK' ) );
		$synchronizer->rolledBack();
	}

	public function test_failed_rollback_after_release_failure_is_not_silently_ignored(): void
	{
		global $wpdb;

		self::assertNotFalse( $wpdb->query( 'START TRANSACTION' ) );
		$synchronizer = new TransactionSynchronizer();
		$failed = [];
		$filter = static function ( string $query ) use ( &$failed ): string {
			$normalized = trim( $query );
			if ( preg_match( '/^(?:RELEASE SAVEPOINT|ROLLBACK TO SAVEPOINT) /i', $normalized ) ) {
				$failed[] = $normalized;
				return 'SELECT * FROM `wpconnections_batch14_failed_release_recovery`';
			}

			return $query;
		};

		$suppress = $wpdb->suppress_errors();
		add_filter( 'query', $filter );
		$failure = null;
		try {
			$this->client->runAtomically(
				'__return_true',
				TransactionContext::nested( $synchronizer )
			);
		} catch ( Throwable $exception ) {
			$failure = $exception;
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $suppress );
		}

		self::assertCount( 2, $failed );
		self::assertStringStartsWith( 'RELEASE SAVEPOINT ', $failed[0] );
		self::assertStringStartsWith( 'ROLLBACK TO SAVEPOINT ', $failed[1] );
		self::assertInstanceOf( StorageFailure::class, $failure );
		self::assertSame( 'rollback savepoint after release failure', $failure->getOperation() );
		self::assertInstanceOf( StorageFailure::class, $failure->getPrevious()->getPrevious() );
		self::assertSame(
			'release savepoint',
			$failure->getPrevious()->getPrevious()->getOperation()
		);

		self::assertNotFalse( $wpdb->query( 'ROLLBACK' ) );
		$synchronizer->rolledBack();
	}

	public function test_swallowed_child_rollback_failure_forces_parent_rollback(): void
	{
		global $wpdb;

		$inner_failure = null;
		$row_id = 0;
		$queries = [];
		$rollback_failed = false;
		$filter = static function ( string $query ) use ( &$queries, &$rollback_failed ): string {
			$normalized = trim( $query );
			$queries[] = $normalized;
			if ( ! $rollback_failed && preg_match( '/^ROLLBACK TO SAVEPOINT /i', $normalized ) ) {
				$rollback_failed = true;
				return 'SELECT * FROM `wpconnections_batch14_failed_child_rollback`';
			}

			return $query;
		};

		$suppress = $wpdb->suppress_errors();
		add_filter( 'query', $filter );
		$outer_failure = null;
		try {
			$this->client->runAtomically(
				function () use ( &$row_id, &$inner_failure ): void {
					try {
						$this->client->runAtomically(
							function () use ( &$row_id ): void {
								$row_id = $this->insert_connection_row( 'unconfirmed-child-rollback' );
								throw new RuntimeException( 'force child rollback' );
							}
						);
					} catch ( Throwable $failure ) {
						$inner_failure = $failure;
					}
				}
			);
		} catch ( Throwable $failure ) {
			$outer_failure = $failure;
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $suppress );
		}

		self::assertTrue( $rollback_failed );
		self::assertInstanceOf( StorageFailure::class, $inner_failure );
		self::assertSame( 'rollback savepoint', $inner_failure->getOperation() );
		self::assertSame( $inner_failure, $outer_failure );
		self::assertSame( 0, $this->query_count( $queries, '/^COMMIT$/i' ) );
		self::assertSame( 1, $this->query_count( $queries, '/^ROLLBACK$/i' ) );
		self::assertFalse( $this->connection_row_exists( $row_id ) );
	}

	public function test_failed_root_rollback_blocks_a_later_scope_before_start(): void
	{
		global $wpdb;

		$row_id = 0;
		$rollback_failed = false;
		$rollback_filter = static function ( string $query ) use ( &$rollback_failed ): string {
			if ( ! $rollback_failed && preg_match( '/^ROLLBACK$/i', trim( $query ) ) ) {
				$rollback_failed = true;
				return 'SELECT * FROM `wpconnections_batch14_failed_root_rollback`';
			}

			return $query;
		};

		$suppress = $wpdb->suppress_errors();
		add_filter( 'query', $rollback_filter );
		$first_failure = null;
		try {
			$this->client->runAtomically(
				function () use ( &$row_id ): void {
					$row_id = $this->insert_connection_row( 'unconfirmed-root-rollback' );
					throw new RuntimeException( 'force root rollback' );
				}
			);
		} catch ( Throwable $failure ) {
			$first_failure = $failure;
		} finally {
			remove_filter( 'query', $rollback_filter );
			$wpdb->suppress_errors( $suppress );
		}

		$next_callback_called = false;
		$next_queries = [];
		$recorder = static function ( string $query ) use ( &$next_queries ): string {
			$next_queries[] = trim( $query );
			return $query;
		};
		add_filter( 'query', $recorder );
		$next_failure = null;
		try {
			$this->client->runAtomically(
				static function () use ( &$next_callback_called ): void {
					$next_callback_called = true;
				}
			);
		} catch ( Throwable $failure ) {
			$next_failure = $failure;
		} finally {
			remove_filter( 'query', $recorder );
		}

		self::assertTrue( $rollback_failed );
		self::assertInstanceOf( StorageFailure::class, $first_failure );
		self::assertSame( 'rollback transaction', $first_failure->getOperation() );
		self::assertInstanceOf( StorageFailure::class, $next_failure );
		self::assertSame( 'transaction state is uncertain', $next_failure->getOperation() );
		self::assertFalse( $next_callback_called );
		self::assertSame( 0, $this->query_count( $next_queries, '/^START TRANSACTION$/i' ) );

		self::assertNotFalse( $wpdb->query( 'ROLLBACK' ) );
		self::assertFalse( $this->connection_row_exists( $row_id ) );
	}

	public function test_nested_notifications_are_fifo_and_exactly_once_after_outer_commit(): void
	{
		global $wpdb;

		self::assertNotFalse( $wpdb->query( 'START TRANSACTION' ) );
		$timeline = [];
		$synchronizer = new TransactionSynchronizer();
		$row_id = $this->client->runAtomically(
			function () use ( &$timeline ): int {
				$row_id = $this->insert_connection_row( 'nested-commit' );
				$this->client->deferSuccessNotification(
					static function () use ( &$timeline ): void {
						$timeline[] = 'first';
					}
				);
				$this->client->deferSuccessNotification(
					static function () use ( &$timeline ): void {
						$timeline[] = 'second';
					}
				);

				return $row_id;
			},
			TransactionContext::nested( $synchronizer )
		);

		self::assertSame( [], $timeline );
		self::assertNotFalse( $wpdb->query( 'COMMIT' ) );
		$synchronizer->committed();
		self::assertSame( [ 'first', 'second' ], $timeline );
		self::assertTrue( $this->connection_row_exists( $row_id ) );

		$this->expectException( LogicException::class );
		$synchronizer->committed();
	}

	public function test_nested_notifications_are_discarded_after_outer_rollback(): void
	{
		global $wpdb;

		self::assertNotFalse( $wpdb->query( 'START TRANSACTION' ) );
		$timeline = [];
		$synchronizer = new TransactionSynchronizer();
		$row_id = $this->client->runAtomically(
			function () use ( &$timeline ): int {
				$row_id = $this->insert_connection_row( 'nested-rollback' );
				$this->client->deferSuccessNotification(
					static function () use ( &$timeline ): void {
						$timeline[] = 'must-not-run';
					}
				);

				return $row_id;
			},
			TransactionContext::nested( $synchronizer )
		);

		self::assertNotFalse( $wpdb->query( 'ROLLBACK' ) );
		$synchronizer->rolledBack();
		self::assertSame( [], $timeline );
		self::assertFalse( $this->connection_row_exists( $row_id ) );

		$this->expectException( LogicException::class );
		$synchronizer->rolledBack();
	}

	public function test_post_commit_notification_throwable_propagates_with_durable_state(): void
	{
		global $wpdb;

		self::assertNotFalse( $wpdb->query( 'START TRANSACTION' ) );
		$expected = new RuntimeException( 'post-commit observer failed' );
		$timeline = [];
		$synchronizer = new TransactionSynchronizer();
		$row_id = $this->client->runAtomically(
			function () use ( $expected, &$timeline ): int {
				$row_id = $this->insert_connection_row( 'nested-hook-failure' );
				$this->client->deferSuccessNotification(
					static function () use ( $expected ): void {
						throw $expected;
					}
				);
				$this->client->deferSuccessNotification(
					static function () use ( &$timeline ): void {
						$timeline[] = 'after-throw';
					}
				);

				return $row_id;
			},
			TransactionContext::nested( $synchronizer )
		);
		self::assertNotFalse( $wpdb->query( 'COMMIT' ) );

		$caught = null;
		try {
			$synchronizer->committed();
		} catch ( Throwable $failure ) {
			$caught = $failure;
		}

		self::assertSame( $expected, $caught );
		self::assertNotInstanceOf( StorageFailure::class, $caught );
		self::assertTrue( $this->connection_row_exists( $row_id ) );
		self::assertSame( [], $timeline );

		$this->expectException( LogicException::class );
		$synchronizer->committed();
	}

	public function test_completed_synchronizer_is_rejected_before_savepoint_and_callback(): void
	{
		global $wpdb;

		self::assertNotFalse( $wpdb->query( 'START TRANSACTION' ) );
		$synchronizer = new TransactionSynchronizer();
		$synchronizer->rolledBack();
		$callback_called = false;
		$queries = [];
		$recorder = static function ( string $query ) use ( &$queries ): string {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $recorder );

		$failure = null;
		try {
			$this->client->runAtomically(
				static function () use ( &$callback_called ): void {
					$callback_called = true;
				},
				TransactionContext::nested( $synchronizer )
			);
		} catch ( Throwable $exception ) {
			$failure = $exception;
		} finally {
			remove_filter( 'query', $recorder );
			$wpdb->query( 'ROLLBACK' );
		}

		self::assertInstanceOf( StorageCapabilityUnavailable::class, $failure );
		self::assertFalse( $callback_called );
		self::assertSame( 0, $this->query_count( $queries, '/^SAVEPOINT /i' ) );
	}

	private function register_relation(): void
	{
		$relation = new RelationQuery();
		$relation->set( 'name', 'atomic-relation' );
		$relation->set( 'from', 'page' );
		$relation->set( 'to', 'post' );
		$relation->set( 'cardinality', 'm-m' );
		$this->client->registerRelation( $relation );
	}

	private function cleanup_client( Client $client ): void
	{
		global $wpdb;

		$client->disablePostDeletionCleanup();
		RestRouteRegistry::instance()->deactivateClient( $client );

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

	private function insert_connection_row( string $relation ): int
	{
		global $wpdb;

		$table = $wpdb->prefix . $this->client->getStorage()->get_connections_table();
		self::assertSame(
			1,
			$wpdb->insert(
				$table,
				[
					'relation' => $relation,
					'from'     => 1001,
					'to'       => 1002,
				]
			)
		);

		return (int) $wpdb->insert_id;
	}

	private function connection_row_exists( int $connection_id ): bool
	{
		global $wpdb;

		$table = $wpdb->prefix . $this->client->getStorage()->get_connections_table();

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE ID = %d", $connection_id )
		) === 1;
	}

	private function savepoint_names( array $queries, string $pattern ): array
	{
		$names = [];
		foreach ( $queries as $query ) {
			if ( 1 === preg_match( $pattern, trim( $query ), $matches ) ) {
				$names[] = $matches[1];
			}
		}

		return $names;
	}

	private function query_count( array $queries, string $pattern ): int
	{
		return count(
			array_filter(
				$queries,
				static function ( string $query ) use ( $pattern ): bool {
					return 1 === preg_match( $pattern, trim( $query ) );
				}
			)
		);
	}

	private function first_query_index( array $queries, string $pattern ): int
	{
		foreach ( $queries as $index => $query ) {
			if ( 1 === preg_match( $pattern, trim( $query ) ) ) {
				return $index;
			}
		}

		self::fail( "No query matched {$pattern}." );
	}
}
