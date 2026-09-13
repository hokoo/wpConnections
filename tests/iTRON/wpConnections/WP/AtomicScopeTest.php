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
use iTRON\wpConnections\WPStorage;
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
