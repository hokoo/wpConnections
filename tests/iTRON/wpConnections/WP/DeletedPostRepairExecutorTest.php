<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use DateTimeImmutable;
use DateTimeZone;
use iTRON\wpConnections\Internal\DeletedPostRepairClockInterface;
use iTRON\wpConnections\Internal\DeletedPostRepairExecutor;
use iTRON\wpConnections\Internal\DeletedPostRepairIdentity;
use iTRON\wpConnections\Internal\DeletedPostRepairLease;
use iTRON\wpConnections\Internal\DeletedPostRepairLedger;
use iTRON\wpConnections\Internal\DeletedPostRepairPolicy;
use iTRON\wpConnections\Internal\DeletedPostRepairRecord;
use iTRON\wpConnections\Internal\DeletedPostRepairStatus;
use iTRON\wpConnections\Query\Connection as ConnectionQuery;
use iTRON\wpConnections\Query\Meta;
use RuntimeException;

final class DeletedPostRepairExecutorWPClock implements DeletedPostRepairClockInterface
{
	private DateTimeImmutable $now;

	public function __construct( DateTimeImmutable $now )
	{
		$this->now = $now;
	}

	public function utcNow(): DateTimeImmutable
	{
		return $this->now;
	}

	public function monotonicSeconds(): float
	{
		return 100.0;
	}

	public function set_now( DateTimeImmutable $now ): void
	{
		$this->now = $now;
	}
}

final class DeletedPostRepairExecutorTest extends WPConnectionsTestCase
{
	private const TABLE_BASENAME = 'wpconnections_repair';
	private const OWNERSHIP_OPTION = 'wpconnections_repair_schema_owner';
	private const OPERATION = 'delete_post_connections:v1';

	private DeletedPostRepairLedger $ledger;
	private DeletedPostRepairExecutorWPClock $clock;
	private DeletedPostRepairPolicy $policy;
	private string $ledger_table;
	private array $wpdb_tables_before_ledger = [];

	public function set_up()
	{
		parent::set_up();

		global $wpdb;
		$this->wpdb_tables_before_ledger = $wpdb->tables;
		$this->ledger_table = $wpdb->prefix . self::TABLE_BASENAME;
		add_filter( 'query', [ $this, 'preserve_real_repair_ledger_table' ], 11 );
		$this->drop_ledger_artifacts();
		$this->clock = new DeletedPostRepairExecutorWPClock( $this->utc( '2026-09-21 10:00:00' ) );
		$this->policy = new DeletedPostRepairPolicy( $this->clock );
		$this->ledger = new DeletedPostRepairLedger();
		$this->ledger->ensureReady();
	}

	public function tear_down()
	{
		global $wpdb;

		try {
			$this->drop_ledger_artifacts();
			$wpdb->tables = $this->wpdb_tables_before_ledger;
		} finally {
			remove_filter( 'query', [ $this, 'preserve_real_repair_ledger_table' ], 11 );
			parent::tear_down();
		}
	}

	public function test_real_storage_cleanup_and_transient_ledger_finalization_succeed(): void
	{
		$this->create_connection_with_meta();
		$identity = $this->identity( $this->post_ids[0] );
		$lease = $this->arm( $identity );

		$result = $this->executor()->execute(
			$this->client,
			$lease,
			DeletedPostRepairExecutor::MODE_AUTOMATIC
		);

		self::assertSame( 'resolved', $result->getOutcome() );
		self::assertTrue( $result->wasCleanupAttempted() );
		self::assertSame( 0, $this->connection_count() );
		self::assertSame( 0, $this->meta_count() );
		self::assertNull( $this->ledger->findForClient( $this->client->getName(), $identity->getKey() ) );
	}

	public function test_cleanup_throwable_rolls_back_storage_and_persists_retry_wait(): void
	{
		$this->create_connection_with_meta();
		$identity = $this->identity( $this->post_ids[0] );
		$lease = $this->arm( $identity );
		$failure = static function (): void {
			throw new RuntimeException( 'sensitive cleanup failure' );
		};
		add_action( 'wpConnections/storage/deleteByObjectID', $failure );

		try {
			$result = $this->executor()->execute(
				$this->client,
				$lease,
				DeletedPostRepairExecutor::MODE_AUTOMATIC
			);
		} finally {
			remove_action( 'wpConnections/storage/deleteByObjectID', $failure );
		}

		self::assertSame( 'retry_wait', $result->getOutcome() );
		self::assertTrue( $result->wasCleanupAttempted() );
		self::assertSame( 1, $this->connection_count() );
		self::assertSame( 1, $this->meta_count() );
		$record = $this->record( $identity );
		self::assertSame( DeletedPostRepairStatus::RETRY_WAIT, $record->getStatus() );
		self::assertSame( 1, $record->getAttemptCount() );
		self::assertSame( 1, $record->getFailureCount() );
		self::assertSame( '2026-09-21 10:01:00', $record->getNextAttemptAt()->format( 'Y-m-d H:i:s' ) );
		self::assertSame( '[diagnostic details redacted]', $record->getFailureDiagnostic()->getSummary() );
	}

	public function test_post_commit_hook_failure_retries_committed_cleanup_and_zero_resolves_uncertainty(): void
	{
		$this->create_connection_with_meta();
		$identity = $this->identity( $this->post_ids[0] );
		$lease = $this->arm( $identity );
		$failure = static function (): void {
			throw new RuntimeException( 'sensitive observer failure' );
		};
		add_action( 'wpConnections/storage/deletedByObjectID', $failure );

		try {
			$first = $this->executor()->execute(
				$this->client,
				$lease,
				DeletedPostRepairExecutor::MODE_AUTOMATIC
			);
		} finally {
			remove_action( 'wpConnections/storage/deletedByObjectID', $failure );
		}

		self::assertSame( 'retry_wait', $first->getOutcome() );
		self::assertSame( 0, $this->connection_count() );
		self::assertSame( 0, $this->meta_count() );
		self::assertSame( DeletedPostRepairStatus::RETRY_WAIT, $this->record( $identity )->getStatus() );

		$retry_at = $this->utc( '2026-09-21 10:01:00' );
		$this->clock->set_now( $retry_at );
		$retry = $this->ledger->tryClaimDue(
			$identity->getKey(),
			$this->client->getStorage(),
			$retry_at,
			$this->policy->leaseExpiresAt( $retry_at )
		);
		$retry_lease = $retry->getLease();
		self::assertInstanceOf( DeletedPostRepairLease::class, $retry_lease );

		$second = $this->executor()->execute(
			$this->client,
			$retry_lease,
			DeletedPostRepairExecutor::MODE_AUTOMATIC
		);

		self::assertSame( 'resolved', $second->getOutcome() );
		$resolved = $this->record( $identity );
		self::assertSame( DeletedPostRepairStatus::RESOLVED, $resolved->getStatus() );
		self::assertSame( 2, $resolved->getAttemptCount() );
		self::assertSame( 1, $resolved->getFailureCount() );
		self::assertEquals( $retry_at, $resolved->getResolvedAt() );
	}

	private function executor(): DeletedPostRepairExecutor
	{
		return new DeletedPostRepairExecutor( $this->ledger, $this->policy );
	}

	private function identity( int $post_id ): DeletedPostRepairIdentity
	{
		global $wpdb;

		return new DeletedPostRepairIdentity(
			get_current_blog_id(),
			(string) $wpdb->prefix,
			$this->client->getName(),
			self::OPERATION,
			$post_id
		);
	}

	private function arm( DeletedPostRepairIdentity $identity ): DeletedPostRepairLease
	{
		$now = $this->policy->utcNow();
		$result = $this->ledger->armAndTryClaim(
			$identity,
			$this->client->getStorage(),
			$now,
			$this->policy->leaseExpiresAt( $now )
		);
		$lease = $result->getLease();
		self::assertSame( 'acquired', $result->getOutcome() );
		self::assertInstanceOf( DeletedPostRepairLease::class, $lease );

		return $lease;
	}

	private function record( DeletedPostRepairIdentity $identity ): DeletedPostRepairRecord
	{
		$record = $this->ledger->findForClient( $this->client->getName(), $identity->getKey() );
		self::assertInstanceOf( DeletedPostRepairRecord::class, $record );

		return $record;
	}

	private function create_connection_with_meta(): void
	{
		$query = new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] );
		$query->meta->add( new Meta( 'repair-marker', 'present' ) );
		$this->client->getRelation( RELATION_0_NAME )->createConnection( $query );
		self::assertSame( 1, $this->connection_count() );
		self::assertSame( 1, $this->meta_count() );
	}

	private function connection_count(): int
	{
		global $wpdb;
		$table = $wpdb->prefix . $this->client->getStorage()->get_connections_table();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
	}

	private function meta_count(): int
	{
		global $wpdb;
		$table = $wpdb->prefix . $this->client->getStorage()->get_meta_table();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
	}

	private function utc( string $time ): DateTimeImmutable
	{
		return new DateTimeImmutable( $time, new DateTimeZone( 'UTC' ) );
	}

	private function drop_ledger_artifacts(): void
	{
		global $wpdb;

		delete_option( self::OWNERSHIP_OPTION );
		wp_cache_delete( self::OWNERSHIP_OPTION, 'options' );
		$wpdb->query( "DROP TABLE IF EXISTS `{$this->ledger_table}`" );
		unset( $wpdb->{self::TABLE_BASENAME} );
		$wpdb->tables = array_values(
			array_filter(
				$wpdb->tables,
				static fn( string $table_key ): bool => self::TABLE_BASENAME !== $table_key
			)
		);
	}

	public function preserve_real_repair_ledger_table( string $query ): string
	{
		$table = preg_quote( $this->ledger_table, '/' );
		$query = (string) preg_replace(
			'/^CREATE\s+TEMPORARY\s+TABLE\s+`' . $table . '`/i',
			'CREATE TABLE `' . $this->ledger_table . '`',
			$query
		);

		return (string) preg_replace(
			'/^DROP\s+TEMPORARY\s+TABLE(\s+IF\s+EXISTS)?\s+`' . $table . '`/i',
			'DROP TABLE$1 `' . $this->ledger_table . '`',
			$query
		);
	}
}
