<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use DateTimeImmutable;
use DateTimeZone;
use iTRON\wpConnections\Abstracts\Connection as AbstractConnection;
use iTRON\wpConnections\Abstracts\Storage;
use iTRON\wpConnections\AtomicStorageInterface;
use iTRON\wpConnections\Client;
use iTRON\wpConnections\ConnectionCollection;
use iTRON\wpConnections\Exceptions\ClientRegisterFail;
use iTRON\wpConnections\Exceptions\DeletedPostRepairUnavailable;
use iTRON\wpConnections\Internal\DeletedPostRepairClientRegistry;
use iTRON\wpConnections\Internal\DeletedPostRepairDiagnostic;
use iTRON\wpConnections\Internal\DeletedPostRepairIdentity;
use iTRON\wpConnections\Internal\DeletedPostRepairLedger;
use iTRON\wpConnections\Internal\DeletedPostRepairStatus;
use iTRON\wpConnections\Internal\RestRouteRegistry;
use iTRON\wpHooksDispatcher\WordPressSiteContextProvider;
use iTRON\wpConnections\MetaCollection;
use iTRON\wpConnections\Query\Connection as ConnectionQuery;
use iTRON\wpConnections\Query\MetaCollection as MetaQueryCollection;
use iTRON\wpConnections\TransactionContext;
use InvalidArgumentException;
use RuntimeException;

final class DeletedPostRepairServiceStorage extends Storage implements AtomicStorageInterface
{
	public static array $deletedPostIds = [];
	public static array $failingPostIds = [];

	public function createConnection( ConnectionQuery $connectionQuery ): int
	{
		return 1;
	}

	public function updateConnection( AbstractConnection $connection ): bool
	{
		return true;
	}

	public function deleteSpecificConnections( $connectionIDs ): int
	{
		return 0;
	}

	public function deleteByObjectID(
		$objectIDs,
		string $relation = '',
		bool $onlyFrom = false,
		bool $onlyTo = false
	): int {
		self::$deletedPostIds[] = $objectIDs;
		if ( isset( self::$failingPostIds[ $objectIDs ] ) ) {
			throw new RuntimeException( 'private storage failure' );
		}

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

	public function addConnectionMeta( int $objectID, MetaCollection $metaCollection ): void
	{
	}

	public function removeConnectionMeta( int $objectID, MetaQueryCollection $metaQuery )
	{
		return 0;
	}

	public function runAtomically( callable $operation, TransactionContext $context )
	{
		return $operation();
	}
}

class DeletedPostRepairServiceTest extends \WP_UnitTestCase
{
	private const TABLE_BASENAME = 'wpconnections_repair';
	private const OWNERSHIP_OPTION = 'wpconnections_repair_schema_owner';
	private const OPERATION = 'delete_post_connections:v1';

	private DeletedPostRepairLedger $ledger;
	private Client $client;
	private array $clients = [];
	private string $table;
	private array $wpdbTablesBefore = [];

	public function set_up()
	{
		parent::set_up();

		global $wpdb;
		$this->wpdbTablesBefore = $wpdb->tables;
		$this->table = $wpdb->prefix . self::TABLE_BASENAME;
		$this->dropLedgerArtifacts();
		$this->ledger = new DeletedPostRepairLedger();
		$this->ledger->ensureReady();
		DeletedPostRepairServiceStorage::$deletedPostIds = [];
		DeletedPostRepairServiceStorage::$failingPostIds = [];
		add_filter( 'wpConnections/factory/getStorage/class', [ $this, 'storage_class' ] );
		$this->client = $this->newClient( 'repair-service-main' );
	}

	public function tear_down()
	{
		global $wpdb;

		try {
			foreach ( $this->clients as $client ) {
				\iTRON\wpConnections\Internal\DeletedPostRepairRuntime::instance()->deactivateClient( $client );
				RestRouteRegistry::instance()->deactivateClient( $client );
			}
			\iTRON\wpConnections\Internal\DeletedPostRepairRuntime::instance()->resetForTests();
			remove_filter( 'wpConnections/factory/getStorage/class', [ $this, 'storage_class' ] );
			$this->dropLedgerArtifacts();
			$wpdb->tables = $this->wpdbTablesBefore;
		} finally {
			parent::tear_down();
		}
	}

	public function storage_class(): string
	{
		return DeletedPostRepairServiceStorage::class;
	}

	public function test_custom_atomic_client_cannot_first_register_after_multisite_switch(): void
	{
		if ( ! is_multisite() ) {
			self::markTestSkipped( 'Requires the true WordPress multisite lane.' );
		}

		$stale_client = $this->newClient( 'repair-stale-first-registration' );
		$registry = new DeletedPostRepairClientRegistry( new WordPressSiteContextProvider() );
		$second_blog_id = self::factory()->blog->create();

		switch_to_blog( $second_blog_id );
		try {
			try {
				$registry->register( $stale_client );
				self::fail( 'A Client from another site must fail before registration.' );
			} catch ( ClientRegisterFail $failure ) {
				self::assertStringContainsString( 'site context', $failure->getMessage() );
			}

			self::assertNull( $registry->resolve( $stale_client->getName() ) );
			self::assertSame( [], DeletedPostRepairServiceStorage::$deletedPostIds );
		} finally {
			restore_current_blog();
		}
	}

	public function test_getter_is_lazy_and_get_is_client_scoped_with_safe_projection(): void
	{
		$queries = [];
		$observe = function ( string $query ) use ( &$queries ): string {
			if ( false !== strpos( $query, $this->table ) ) {
				$queries[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $observe );
		try {
			$service = $this->client->getDeletedPostRepairService();
			self::assertSame( [], $queries );
			self::assertSame( $service, $this->client->getDeletedPostRepairService() );
		} finally {
			remove_filter( 'query', $observe );
		}

		$record = $this->failedDueRecord( $this->client, 201 );
		$other = $this->newClient( 'repair-service-other' );
		$foreign = $this->failedDueRecord( $other, 202 );
		$view = $service->getRepair( $record->getKey() );

		self::assertNotNull( $view );
		self::assertSame( $record->getKey(), $view->getRepairKey() );
		self::assertSame( 201, $view->getPostId() );
		self::assertSame( DeletedPostRepairStatus::RETRY_WAIT, $view->getStatus() );
		self::assertSame( 'storage', $view->getFailureCategory() );
		self::assertSame( '[diagnostic details redacted]', $view->getFailureSummary() );
		self::assertNull( $service->getRepair( $foreign->getKey() ) );
		self::assertNull( $service->getRepair( str_repeat( 'f', 64 ) ) );
	}

	public function test_list_is_status_filtered_keyset_paginated_and_does_not_expose_foreign_rows(): void
	{
		$records = [
			$this->failedDueRecord( $this->client, 211 ),
			$this->failedDueRecord( $this->client, 212 ),
			$this->failedDueRecord( $this->client, 213 ),
		];
		$this->failedDueRecord( $this->newClient( 'repair-page-foreign' ), 214 );
		usort(
			$records,
			static fn( DeletedPostRepairIdentity $left, DeletedPostRepairIdentity $right ): int =>
				strcmp( $left->getKey(), $right->getKey() )
		);

		$first = $this->client->getDeletedPostRepairService()->listRepairs(
			DeletedPostRepairStatus::RETRY_WAIT,
			null,
			2
		);
		self::assertSame(
			[ $records[0]->getKey(), $records[1]->getKey() ],
			array_map( static fn( $view ): string => $view->getRepairKey(), $first->getItems() )
		);
		self::assertSame( $records[1]->getKey(), $first->getNextAfterKey() );

		$second = $this->client->getDeletedPostRepairService()->listRepairs(
			DeletedPostRepairStatus::RETRY_WAIT,
			$first->getNextAfterKey(),
			2
		);
		self::assertSame( [ $records[2]->getKey() ], array_map(
			static fn( $view ): string => $view->getRepairKey(),
			$second->getItems()
		) );
		self::assertNull( $second->getNextAfterKey() );
	}

	public function test_retry_outcomes_are_distinct_and_cleanup_failures_are_redacted(): void
	{
		$service = $this->client->getDeletedPostRepairService();
		$success = $this->expiredRunningRecord( $this->client, 221 );
		$live = $this->liveRunningRecord( $this->client, 222 );
		$failed = $this->expiredRunningRecord( $this->client, 223 );
		$mismatch = $this->identity( $this->client, 224 );
		$now = $this->now();
		self::assertNotNull( $this->ledger->armAndTryClaim(
			$mismatch,
			new \stdClass(),
			$now->modify( '-20 minutes' ),
			$now->modify( '-10 minutes' )
		)->getLease() );
		DeletedPostRepairServiceStorage::$failingPostIds[223] = true;

		$successResult = $service->retryRepair( $success->getKey() );
		self::assertSame( 'resolved', $successResult->getOutcome() );
		self::assertTrue( $successResult->wasCleanupAttempted() );
		self::assertNull( $successResult->getRepair() );

		$runningResult = $service->retryRepair( $live->getKey() );
		self::assertSame( 'already_running', $runningResult->getOutcome() );
		self::assertFalse( $runningResult->wasCleanupAttempted() );
		self::assertNotNull( $runningResult->getRepair() );

		$missingResult = $service->retryRepair( str_repeat( 'e', 64 ) );
		self::assertSame( 'not_found_or_foreign', $missingResult->getOutcome() );
		self::assertFalse( $missingResult->wasCleanupAttempted() );
		self::assertNull( $missingResult->getRepair() );

		$failedResult = $service->retryRepair( $failed->getKey() );
		self::assertSame( 'retry_failed', $failedResult->getOutcome() );
		self::assertTrue( $failedResult->wasCleanupAttempted() );
		self::assertSame( DeletedPostRepairStatus::NEEDS_ATTENTION, $failedResult->getRepair()->getStatus() );
		self::assertSame( '[diagnostic details redacted]', $failedResult->getRepair()->getFailureSummary() );

		$mismatchResult = $service->retryRepair( $mismatch->getKey() );
		self::assertSame( 'retry_failed', $mismatchResult->getOutcome() );
		self::assertFalse( $mismatchResult->wasCleanupAttempted() );
		self::assertSame( DeletedPostRepairStatus::NEEDS_ATTENTION, $mismatchResult->getRepair()->getStatus() );
		self::assertNotContains( 224, DeletedPostRepairServiceStorage::$deletedPostIds );
	}

	public function test_due_batch_is_bounded_uses_manual_mode_and_reports_exact_more_state(): void
	{
		$this->client->disablePostDeletionCleanup();
		$first = $this->expiredRunningRecord( $this->client, 231 );
		$second = $this->expiredRunningRecord( $this->client, 232 );
		$keys = [ $first->getKey(), $second->getKey() ];
		sort( $keys, SORT_STRING );

		$batch = $this->client->getDeletedPostRepairService()->retryDueRepairs( 1, 10 );
		self::assertSame( 'batch_limit', $batch->getStopReason() );
		self::assertTrue( $batch->hasMoreDue() );
		self::assertCount( 1, $batch->getResults() );
		self::assertSame( $keys[0], $batch->getResults()[0]->getRepairKey() );
		self::assertSame( 'resolved', $batch->getResults()[0]->getOutcome() );

		$last = $this->client->getDeletedPostRepairService()->retryDueRepairs( 1, 10 );
		self::assertSame( 'complete', $last->getStopReason() );
		self::assertFalse( $last->hasMoreDue() );
		self::assertSame( $keys[1], $last->getResults()[0]->getRepairKey() );
		$deletedPostIds = array_values( array_unique( DeletedPostRepairServiceStorage::$deletedPostIds ) );
		sort( $deletedPostIds, SORT_NUMERIC );
		self::assertSame( [ 231, 232 ], $deletedPostIds );
	}

	public function test_invalid_input_and_stale_context_fail_before_ledger_sql(): void
	{
		$service = $this->client->getDeletedPostRepairService();
		$queries = [];
		$observe = function ( string $query ) use ( &$queries ): string {
			if ( false !== strpos( $query, $this->table ) ) {
				$queries[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $observe );
		try {
			foreach ( [
				static fn() => $service->getRepair( 'BAD' ),
				static fn() => $service->listRepairs( 'unknown' ),
				static fn() => $service->listRepairs( null, str_repeat( 'A', 64 ) ),
				static fn() => $service->listRepairs( null, null, 101 ),
				static fn() => $service->retryDueRepairs( 20, 61 ),
			] as $operation ) {
				try {
					$operation();
					self::fail( 'Invalid public input must fail.' );
				} catch ( InvalidArgumentException $failure ) {
					self::assertNotSame( '', $failure->getMessage() );
				}
			}
			self::assertSame( [], $queries );

			global $wpdb;
			$originalPrefix = $wpdb->prefix;
			$wpdb->prefix = 'stale_context_';
			try {
				$service->getRepair( str_repeat( 'a', 64 ) );
				self::fail( 'A stale Client context must fail.' );
			} catch ( DeletedPostRepairUnavailable $failure ) {
				self::assertStringNotContainsString( 'stale_context_', $failure->getMessage() );
			} finally {
				$wpdb->prefix = $originalPrefix;
			}
			self::assertSame( [], $queries );
		} finally {
			remove_filter( 'query', $observe );
		}
	}

	private function newClient( string $name ): Client
	{
		$client = new Client( $name );
		$this->clients[] = $client;

		return $client;
	}

	private function failedDueRecord( Client $client, int $postId ): DeletedPostRepairIdentity
	{
		$now = $this->now();
		$identity = $this->identity( $client, $postId );
		$claim = $this->ledger->armAndTryClaim(
			$identity,
			$client->getStorage(),
			$now->modify( '-20 minutes' ),
			$now->modify( '-10 minutes' )
		);
		self::assertNotNull( $claim->getLease() );
		self::assertTrue( $this->ledger->markRetryWait(
			$claim->getLease(),
			new DeletedPostRepairDiagnostic( 'storage', RuntimeException::class, '73', 'private' ),
			$now->modify( '-1 minute' ),
			$now->modify( '-9 minutes' )
		) );

		return $identity;
	}

	private function expiredRunningRecord( Client $client, int $postId ): DeletedPostRepairIdentity
	{
		$now = $this->now();
		$identity = $this->identity( $client, $postId );
		$claim = $this->ledger->armAndTryClaim(
			$identity,
			$client->getStorage(),
			$now->modify( '-20 minutes' ),
			$now->modify( '-10 minutes' )
		);
		self::assertNotNull( $claim->getLease() );

		return $identity;
	}

	private function liveRunningRecord( Client $client, int $postId ): DeletedPostRepairIdentity
	{
		$now = $this->now();
		$identity = $this->identity( $client, $postId );
		$claim = $this->ledger->armAndTryClaim(
			$identity,
			$client->getStorage(),
			$now,
			$now->modify( '+10 minutes' )
		);
		self::assertNotNull( $claim->getLease() );

		return $identity;
	}

	private function identity( Client $client, int $postId ): DeletedPostRepairIdentity
	{
		global $wpdb;

		return new DeletedPostRepairIdentity(
			(int) get_current_blog_id(),
			(string) $wpdb->prefix,
			$client->getName(),
			self::OPERATION,
			$postId
		);
	}

	private function now(): DateTimeImmutable
	{
		return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	private function dropLedgerArtifacts(): void
	{
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_BASENAME;
		delete_option( self::OWNERSHIP_OPTION );
		wp_cache_delete( self::OWNERSHIP_OPTION, 'options' );
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		unset( $wpdb->{self::TABLE_BASENAME} );
		$wpdb->tables = array_values(
			array_filter(
				$wpdb->tables,
				static fn( string $tableKey ): bool => self::TABLE_BASENAME !== $tableKey
			)
		);
	}

}
