<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Client;
use iTRON\wpConnections\Exceptions\StorageFailure;
use iTRON\wpConnections\Helpers\Database;
use iTRON\wpConnections\Internal\RestRouteRegistry;
use iTRON\wpConnections\Query\Relation as RelationQuery;
use iTRON\wpConnections\TransactionContext;
use iTRON\wpConnections\WPStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

class AtomicScopeTest extends TestCase
{
	private Client $client;
	private array $wpdb_tables_before = [];

	protected function setUp(): void
	{
		parent::setUp();

		global $wpdb;
		$this->wpdb_tables_before = $wpdb->tables;
		add_filter( 'wpConnections/storage/installOnInit', '__return_true', 10, 2 );

		$name = 'atomic-scope-' . substr( hash( 'sha256', $this->getName() ), 0, 12 );
		$this->client = new Client( $name );
		$this->register_relation();
	}

	protected function tearDown(): void
	{
		global $wpdb;

		try {
			$wpdb->query( 'ROLLBACK' );
			$this->client->disablePostDeletionCleanup();
			RestRouteRegistry::instance()->deactivateClient( $this->client );

			$postfix = Database::normalize_table_name( $this->client->getName() );
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
				'wpconnections_storage_owner_' . hash( 'sha256', str_replace( '-', '_', $this->client->getName() ) )
			);
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
		self::assertLessThan(
			$this->first_query_index( $queries, '/^COMMIT$/i' ),
			$this->first_query_index( $queries, '/^START TRANSACTION$/i' )
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

	private function register_relation(): void
	{
		$relation = new RelationQuery();
		$relation->set( 'name', 'atomic-relation' );
		$relation->set( 'from', 'page' );
		$relation->set( 'to', 'post' );
		$relation->set( 'cardinality', 'm-m' );
		$this->client->registerRelation( $relation );
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
