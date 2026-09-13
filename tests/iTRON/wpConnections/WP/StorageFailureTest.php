<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Connection;
use iTRON\wpConnections\Exceptions\StorageFailure;
use iTRON\wpConnections\Meta;
use iTRON\wpConnections\MetaCollection;
use iTRON\wpConnections\Query\Connection as ConnectionQuery;
use iTRON\wpConnections\Query\Meta as QueryMeta;
use iTRON\wpConnections\Query\MetaCollection as QueryMetaCollection;
use Throwable;

class StorageFailureTest extends WPConnectionsTestCase
{
	public function test_create_database_failure_uses_stable_storage_exception(): void
	{
		$table = $this->connections_table();
		$failure = $this->capture_database_failure(
			"INSERT INTO `{$table}`",
			function (): void {
				$query = new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] );
				$this->client->getRelation( RELATION_0_NAME )->createConnection( $query );
			}
		);

		$this->assert_storage_failure( 'create connection', $failure );
	}

	public function test_update_database_failure_differs_from_valid_noop(): void
	{
		$connection = $this->create_connection();
		$update = new ConnectionQuery();
		$update->set( 'id', $connection->id );
		$update->set( 'title', 'Rejected by database' );

		$failure = $this->capture_database_failure(
			"UPDATE `{$this->connections_table()}`",
			function () use ( $update ): void {
				$this->client->getRelation( RELATION_0_NAME )->updateConnection( $update );
			}
		);

		$this->assert_storage_failure( 'update connection', $failure );

		$noop = new ConnectionQuery();
		$noop->set( 'id', $connection->id );
		self::assertFalse( $this->client->getRelation( RELATION_0_NAME )->updateConnection( $noop ) );
	}

	public function test_add_meta_database_failure_does_not_emit_after_hook(): void
	{
		$connection = $this->create_connection();
		$meta = new MetaCollection();
		$meta->add( new Meta( 'failed-add', 'value' ) );
		$after_calls = 0;
		$after = static function () use ( &$after_calls ): void {
			$after_calls++;
		};
		add_action( 'wpConnections/storage/addConnectionMeta/after', $after );

		try {
			$failure = $this->capture_database_failure(
				"INSERT INTO `{$this->meta_table()}`",
				function () use ( $connection, $meta ): void {
					$this->client->getStorage()->addConnectionMeta( $connection->id, $meta );
				}
			);
		} finally {
			remove_action( 'wpConnections/storage/addConnectionMeta/after', $after );
		}

		$this->assert_storage_failure( 'add connection metadata', $failure );
		self::assertSame( 0, $after_calls );
	}

	public function test_remove_meta_database_failure_does_not_become_zero_or_emit_after_hook(): void
	{
		$connection = $this->create_connection( true );
		$after_calls = 0;
		$after = static function () use ( &$after_calls ): void {
			$after_calls++;
		};
		add_action( 'wpConnections/storage/removeConnectionMeta/after', $after );

		try {
			$failure = $this->capture_database_failure(
				"DELETE FROM {$this->meta_table()}",
				function () use ( $connection ): void {
					$this->client->getStorage()->removeConnectionMeta(
						$connection->id,
						new QueryMetaCollection()
					);
				}
			);
		} finally {
			remove_action( 'wpConnections/storage/removeConnectionMeta/after', $after );
		}

		$this->assert_storage_failure( 'remove connection metadata', $failure );
		self::assertSame( 0, $after_calls );
	}

	public function test_delete_database_failure_does_not_become_zero_or_emit_success_hook(): void
	{
		$connection = $this->create_connection( true );
		$success_calls = 0;
		$success = static function () use ( &$success_calls ): void {
			$success_calls++;
		};
		add_action( 'wpConnections/storage/deletedSpecificConnections', $success );

		try {
			$failure = $this->capture_database_failure(
				"DELETE FROM {$this->connections_table()}",
				function () use ( $connection ): void {
					$this->client->getStorage()->deleteSpecificConnections( $connection->id );
				}
			);
		} finally {
			remove_action( 'wpConnections/storage/deletedSpecificConnections', $success );
		}

		$this->assert_storage_failure( 'delete connections', $failure );
		self::assertSame( 0, $success_calls );
		self::assertSame( 0, $this->client->getStorage()->deleteSpecificConnections( PHP_INT_MAX ) );
	}

	private function create_connection( bool $with_meta = false ): Connection
	{
		$query = new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] );
		if ( $with_meta ) {
			$query->meta->add( new QueryMeta( 'preserved', 'value' ) );
		}

		return $this->client->getRelation( RELATION_0_NAME )->createConnection( $query );
	}

	private function capture_database_failure( string $query_fragment, callable $operation ): ?Throwable
	{
		global $wpdb;

		$intercepted = false;
		$query_filter = static function ( string $query ) use ( $query_fragment, &$intercepted ): string {
			if ( ! $intercepted && false !== strpos( $query, $query_fragment ) ) {
				$intercepted = true;
				return 'SELECT * FROM `wpconnections_batch14_forced_database_failure`';
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

		self::assertTrue( $intercepted, "Expected to intercept query containing {$query_fragment}." );

		return $failure;
	}

	private function assert_storage_failure( string $operation, ?Throwable $failure ): void
	{
		self::assertInstanceOf( StorageFailure::class, $failure );
		self::assertSame( StorageFailure::CODE, $failure->getCode() );
		self::assertSame( $operation, $failure->getOperation() );
		self::assertSame( "Storage operation failed: {$operation}.", $failure->getMessage() );
		self::assertInstanceOf( \RuntimeException::class, $failure->getPrevious() );
		self::assertStringContainsString(
			'wpconnections_batch14_forced_database_failure',
			$failure->getPrevious()->getMessage()
		);
		self::assertStringNotContainsString(
			'wpconnections_batch14_forced_database_failure',
			$failure->getMessage()
		);
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
}
