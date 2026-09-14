<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use iTRON\wpConnections\Exceptions\StorageFailure;
use iTRON\wpConnections\Internal\DeletedPostRepairClaimResult;
use iTRON\wpConnections\Internal\DeletedPostRepairDiagnostic;
use iTRON\wpConnections\Internal\DeletedPostRepairIdentity;
use iTRON\wpConnections\Internal\DeletedPostRepairLease;
use iTRON\wpConnections\Internal\DeletedPostRepairLedger;
use iTRON\wpConnections\Internal\DeletedPostRepairRecord;
use iTRON\wpConnections\Internal\DeletedPostRepairStatus;
use InvalidArgumentException;
use Throwable;

class DeletedPostRepairLedgerStorageA
{
}

class DeletedPostRepairLedgerStorageB
{
}

class DeletedPostRepairLedgerTest extends \WP_UnitTestCase
{
	private const TABLE_BASENAME = 'wpconnections_repair';
	private const OWNERSHIP_OPTION = 'wpconnections_repair_schema_owner';
	private const OPERATION = 'delete_post_connections:v1';

	private DeletedPostRepairLedger $ledger;
	private DateTimeImmutable $now;
	private string $table;
	private array $wpdb_tables_before = [];

	public function set_up()
	{
		parent::set_up();

		global $wpdb;
		$this->wpdb_tables_before = $wpdb->tables;
		$this->table = $wpdb->prefix . self::TABLE_BASENAME;
		add_filter( 'query', [ $this, 'preserve_real_repair_ledger_table' ], 11 );
		$this->drop_ledger_artifacts();
		$this->now = new DateTimeImmutable( '2026-09-14 00:00:00', new DateTimeZone( 'UTC' ) );
		$this->ledger = new DeletedPostRepairLedger();
		$this->ledger->ensureReady();
	}

	public function tear_down()
	{
		global $wpdb;

		try {
			$this->drop_ledger_artifacts();
			$wpdb->tables = $this->wpdb_tables_before;
		} finally {
			remove_filter( 'query', [ $this, 'preserve_real_repair_ledger_table' ], 11 );
			parent::tear_down();
		}
	}

	public function test_arm_claim_persists_immutable_identity_adapter_and_running_counters(): void
	{
		$identity = $this->identity( 'ledger-client-a', 101 );
		$storage = $this->anonymous_storage();
		$claim = $this->ledger->armAndTryClaim(
			$identity,
			$storage,
			$this->now,
			$this->instantAt( '+10 minutes' )
		);

		$lease = $this->assert_acquired( $claim, $identity->getKey() );
		$record = $this->require_record( 'ledger-client-a', $identity->getKey() );

		$this->assert_identity_same( $identity, $record->getIdentity() );
		self::assertSame( DeletedPostRepairStatus::RUNNING, $record->getStatus() );
		self::assertSame( 1, $record->getAttemptCount() );
		self::assertSame( 0, $record->getFailureCount() );
		self::assertSame( $lease->getToken(), $record->getLeaseToken() );
		self::assertEquals( $this->instantAt( '+10 minutes' ), $record->getLeaseExpiresAt() );
		self::assertNull( $record->getNextAttemptAt() );
		self::assertSame( hash( 'sha256', get_class( $storage ) ), $record->getStorageFingerprint() );
		self::assertNotSame( '', $record->getStorageClass() );
		self::assertLessThanOrEqual( 191, strlen( $record->getStorageClass() ) );
		self::assertSame( 0, preg_match( '/[\x00-\x1F\x7F]/', $record->getStorageClass() ) );
		self::assertStringNotContainsString( __FILE__, $record->getStorageClass() );
	}

	public function test_duplicate_live_arm_is_deduplicated_without_incrementing_attempts(): void
	{
		$identity = $this->identity( 'ledger-client-a', 102 );
		$storage = new DeletedPostRepairLedgerStorageA();
		$first = $this->ledger->armAndTryClaim(
			$identity,
			$storage,
			$this->now,
			$this->instantAt( '+10 minutes' )
		);
		$first_lease = $this->assert_acquired( $first, $identity->getKey() );

		$duplicate = $this->ledger->armAndTryClaim(
			new DeletedPostRepairIdentity(
				$identity->getSiteId(),
				$identity->getSitePrefix(),
				$identity->getClientName(),
				$identity->getOperation(),
				$identity->getPostId()
			),
			$storage,
			$this->instantAt( '+1 minute' ),
			$this->instantAt( '+11 minutes' )
		);

		self::assertSame( 'already_running', $duplicate->getOutcome() );
		self::assertNull( $duplicate->getLease() );
		$record = $this->require_record( 'ledger-client-a', $identity->getKey() );
		self::assertSame( 1, $record->getAttemptCount() );
		self::assertSame( 0, $record->getFailureCount() );
		self::assertSame( $first_lease->getToken(), $record->getLeaseToken() );
		self::assertEquals( $this->instantAt( '+10 minutes' ), $record->getLeaseExpiresAt() );
	}

	public function test_two_database_contenders_cannot_both_acquire_one_live_lease(): void
	{
		global $wpdb;

		// WP_UnitTestCase normally rewrites test DDL to a connection-local
		// temporary table and disables autocommit. This test deliberately needs
		// one real, short-lived table that both database sessions can observe.
		$this->drop_ledger_artifacts();
		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		self::assertNotFalse( $wpdb->query( 'SET autocommit = 1' ) );
		$this->ledger = new DeletedPostRepairLedger();
		$this->ledger->ensureReady();

		$identity = $this->identity( 'ledger-client-a', 130 );
		$storage = new DeletedPostRepairLedgerStorageA();
		$contender = $this->secondary_database_connection();
		$contender->query( 'SET SESSION innodb_lock_wait_timeout = 2' );
		$contender_ran = false;
		$contender_affected_rows = null;
		$run_contender_before_primary = function ( string $query ) use (
			$contender,
			&$contender_ran,
			&$contender_affected_rows
		): string {
			if (
				! $contender_ran
				&& 1 === preg_match(
					'/^\s*UPDATE\s+`?' . preg_quote( $this->table, '/' ) . '`?\s+SET\b/i',
					$query
				)
				&& false !== stripos( $query, 'lease_token' )
				&& false !== stripos( $query, 'attempt_count' )
			) {
				$contender_ran = true;
				self::assertTrue( $contender->query( $query ), $contender->error );
				$contender_affected_rows = $contender->affected_rows;
			}

			return $query;
		};
		add_filter( 'query', $run_contender_before_primary );
		try {
			$primary = $this->ledger->armAndTryClaim(
				$identity,
				$storage,
				$this->now,
				$this->instantAt( '+10 minutes' )
			);
		} finally {
			remove_filter( 'query', $run_contender_before_primary );
			$contender->close();
		}

		self::assertTrue( $contender_ran, 'The independent contender never reached the claim UPDATE.' );
		self::assertSame( 1, $contender_affected_rows );
		self::assertSame( 'already_running', $primary->getOutcome() );
		self::assertNull( $primary->getLease() );

		$record = $this->require_record( 'ledger-client-a', $identity->getKey() );
		self::assertSame( DeletedPostRepairStatus::RUNNING, $record->getStatus() );
		self::assertSame( 1, $record->getAttemptCount() );
		self::assertSame( 0, $record->getFailureCount() );
	}

	public function test_arm_and_failure_transition_do_not_manage_a_database_transaction(): void
	{
		$identity = $this->identity( 'ledger-client-a', 131 );
		$storage = new DeletedPostRepairLedgerStorageA();
		$queries = [];
		$recorder = static function ( string $query ) use ( &$queries ): string {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $recorder );
		try {
			$claim = $this->ledger->armAndTryClaim(
				$identity,
				$storage,
				$this->now,
				$this->instantAt( '+10 minutes' )
			);
			$lease = $this->assert_acquired( $claim, $identity->getKey() );
			$transitioned = $this->ledger->markRetryWait(
				$lease,
				$this->diagnostic( 'storage', 'representative failure' ),
				$this->instantAt( '+15 minutes' ),
				$this->instantAt( '+1 minute' )
			);
		} finally {
			remove_filter( 'query', $recorder );
		}

		self::assertTrue( $transitioned );
		self::assertSame( [], $this->transaction_control_queries( $queries ) );
		$record = $this->record( $identity );
		self::assertSame( DeletedPostRepairStatus::RETRY_WAIT, $record->getStatus() );
		self::assertSame( 1, $record->getAttemptCount() );
		self::assertSame( 1, $record->getFailureCount() );
		self::assertEquals( $this->instantAt( '+15 minutes' ), $record->getNextAttemptAt() );
		self::assertNull( $record->getLeaseToken() );
		self::assertNull( $record->getLeaseExpiresAt() );
	}

	public function test_lease_window_must_remain_positive_at_database_second_precision(): void
	{
		$now = DateTimeImmutable::createFromFormat(
			'!Y-m-d H:i:s.u',
			'2026-09-14 00:00:00.100000',
			new DateTimeZone( 'UTC' )
		);
		$lease_until = DateTimeImmutable::createFromFormat(
			'!Y-m-d H:i:s.u',
			'2026-09-14 00:00:00.900000',
			new DateTimeZone( 'UTC' )
		);
		self::assertInstanceOf( DateTimeImmutable::class, $now );
		self::assertInstanceOf( DateTimeImmutable::class, $lease_until );

		$queries = [];
		$recorder = function ( string $query ) use ( &$queries ): string {
			if ( false !== strpos( $query, $this->table ) ) {
				$queries[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $recorder );
		try {
			$failure = $this->capture_failure(
				fn() => $this->ledger->armAndTryClaim(
					$this->identity( 'ledger-client-a', 134 ),
					new DeletedPostRepairLedgerStorageA(),
					$now,
					$lease_until
				)
			);
		} finally {
			remove_filter( 'query', $recorder );
		}

		self::assertInstanceOf( InvalidArgumentException::class, $failure );
		self::assertSame( [], $this->ledger_mutation_queries( $queries ) );
	}

	public function test_adapter_fingerprint_mismatch_fails_closed_without_claim_or_connection_dml(): void
	{
		$identity = $this->identity( 'ledger-client-a', 103 );
		$original_storage = new DeletedPostRepairLedgerStorageA();
		$initial = $this->ledger->armAndTryClaim(
			$identity,
			$original_storage,
			$this->now,
			$this->instantAt( '+5 minutes' )
		);
		$initial_lease = $this->assert_acquired( $initial, $identity->getKey() );
		self::assertTrue(
			$this->ledger->markRetryWait(
				$initial_lease,
				$this->diagnostic( 'storage', 'retryable failure' ),
				$this->instantAt( '+10 minutes' ),
				$this->instantAt( '+1 minute' )
			)
		);
		$before = $this->require_record( 'ledger-client-a', $identity->getKey() );

		$queries = [];
		$recorder = static function ( string $query ) use ( &$queries ): string {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $recorder );
		try {
			$result = $this->ledger->tryClaimDue(
				$identity->getKey(),
				new DeletedPostRepairLedgerStorageB(),
				$this->instantAt( '+10 minutes' ),
				$this->instantAt( '+20 minutes' )
			);
		} finally {
			remove_filter( 'query', $recorder );
		}

		self::assertSame( 'adapter_mismatch', $result->getOutcome() );
		self::assertNull( $result->getLease() );
		$after = $this->require_record( 'ledger-client-a', $identity->getKey() );
		$this->assert_identity_same( $before->getIdentity(), $after->getIdentity() );
		self::assertSame( $before->getStorageFingerprint(), $after->getStorageFingerprint() );
		self::assertSame( $before->getAttemptCount(), $after->getAttemptCount() );
		self::assertSame( $before->getFailureCount(), $after->getFailureCount() );
		self::assertSame( DeletedPostRepairStatus::NEEDS_ATTENTION, $after->getStatus() );
		self::assertNull( $after->getLeaseToken() );
		self::assertSame( [], $this->connection_table_dml( $queries ) );
	}

	public function test_due_not_due_expired_and_manual_claims_have_distinct_results(): void
	{
		$storage = new DeletedPostRepairLedgerStorageA();
		$due = $this->identity( 'ledger-client-a', 104 );
		$future = $this->identity( 'ledger-client-a', 105 );
		$expired = $this->identity( 'ledger-client-a', 106 );
		$manual = $this->identity( 'ledger-client-a', 107 );

		$due_lease = $this->arm( $due, $storage, '+5 minutes' );
		self::assertTrue(
			$this->ledger->markRetryWait(
				$due_lease,
				$this->diagnostic( 'storage', 'due failure' ),
				$this->instantAt( '+10 minutes' ),
				$this->instantAt( '+1 minute' )
			)
		);
		$future_lease = $this->arm( $future, $storage, '+5 minutes' );
		self::assertTrue(
			$this->ledger->markRetryWait(
				$future_lease,
				$this->diagnostic( 'storage', 'future failure' ),
				$this->instantAt( '+30 minutes' ),
				$this->instantAt( '+1 minute' )
			)
		);
		$this->arm( $expired, $storage, '+5 minutes' );
		$manual_lease = $this->arm( $manual, $storage, '+5 minutes' );
		self::assertTrue(
			$this->ledger->markNeedsAttention(
				$manual_lease,
				$this->diagnostic( 'storage', 'manual intervention' ),
				$this->instantAt( '+1 minute' )
			)
		);

		$not_due = $this->ledger->tryClaimDue(
			$future->getKey(),
			$storage,
			$this->instantAt( '+10 minutes' ),
			$this->instantAt( '+20 minutes' )
		);
		self::assertSame( 'not_due', $not_due->getOutcome() );
		self::assertNull( $not_due->getLease() );

		$due_result = $this->ledger->tryClaimDue(
			$due->getKey(),
			$storage,
			$this->instantAt( '+10 minutes' ),
			$this->instantAt( '+20 minutes' )
		);
		$this->assert_acquired( $due_result, $due->getKey() );

		$expired_result = $this->ledger->tryClaimDue(
			$expired->getKey(),
			$storage,
			$this->instantAt( '+10 minutes' ),
			$this->instantAt( '+20 minutes' )
		);
		$this->assert_acquired( $expired_result, $expired->getKey() );

		$manual_result = $this->ledger->tryClaimManually(
			$manual->getKey(),
			$storage,
			$this->instantAt( '+10 minutes' ),
			$this->instantAt( '+20 minutes' )
		);
		$this->assert_acquired( $manual_result, $manual->getKey() );

		foreach ( [ $due, $expired, $manual ] as $claimed_identity ) {
			$record = $this->require_record( 'ledger-client-a', $claimed_identity->getKey() );
			self::assertSame( DeletedPostRepairStatus::RUNNING, $record->getStatus() );
			self::assertSame( 2, $record->getAttemptCount() );
		}
		self::assertSame( 1, $this->require_record( 'ledger-client-a', $due->getKey() )->getFailureCount() );
		self::assertSame( 0, $this->require_record( 'ledger-client-a', $expired->getKey() )->getFailureCount() );
		self::assertSame( 1, $this->require_record( 'ledger-client-a', $manual->getKey() )->getFailureCount() );

		$live = $this->ledger->tryClaimDue(
			$due->getKey(),
			$storage,
			$this->instantAt( '+11 minutes' ),
			$this->instantAt( '+21 minutes' )
		);
		self::assertSame( 'already_running', $live->getOutcome() );
		self::assertNull( $live->getLease() );

		$missing = $this->ledger->tryClaimDue(
			str_repeat( 'f', 64 ),
			$storage,
			$this->instantAt( '+10 minutes' ),
			$this->instantAt( '+20 minutes' )
		);
		self::assertSame( 'not_found', $missing->getOutcome() );
		self::assertNull( $missing->getLease() );
	}

	public function test_terminal_transitions_reject_a_stale_lease_token(): void
	{
		$storage = new DeletedPostRepairLedgerStorageA();

		$retry = $this->identity( 'ledger-client-a', 108 );
		[ $retry_stale, $retry_current ] = $this->stale_and_current_leases( $retry, $storage );
		self::assertFalse(
			$this->ledger->markRetryWait(
				$retry_stale,
				$this->diagnostic( 'storage', 'stale retry' ),
				$this->instantAt( '+30 minutes' ),
				$this->instantAt( '+11 minutes' )
			)
		);
		self::assertSame( DeletedPostRepairStatus::RUNNING, $this->record( $retry )->getStatus() );
		self::assertTrue(
			$this->ledger->markRetryWait(
				$retry_current,
				$this->diagnostic( 'storage', 'current retry' ),
				$this->instantAt( '+30 minutes' ),
				$this->instantAt( '+11 minutes' )
			)
		);

		$attention = $this->identity( 'ledger-client-a', 109 );
		[ $attention_stale, $attention_current ] = $this->stale_and_current_leases( $attention, $storage );
		self::assertFalse(
			$this->ledger->markNeedsAttention(
				$attention_stale,
				$this->diagnostic( 'storage', 'stale attention' ),
				$this->instantAt( '+11 minutes' )
			)
		);
		self::assertTrue(
			$this->ledger->markNeedsAttention(
				$attention_current,
				$this->diagnostic( 'storage', 'current attention' ),
				$this->instantAt( '+11 minutes' )
			)
		);

		$resolved = $this->identity( 'ledger-client-a', 110 );
		$first = $this->arm( $resolved, $storage, '+5 minutes' );
		self::assertTrue(
			$this->ledger->markRetryWait(
				$first,
				$this->diagnostic( 'storage', 'failed before success' ),
				$this->instantAt( '+10 minutes' ),
				$this->instantAt( '+1 minute' )
			)
		);
		$current = $this->assert_acquired(
			$this->ledger->tryClaimDue(
				$resolved->getKey(),
				$storage,
				$this->instantAt( '+10 minutes' ),
				$this->instantAt( '+20 minutes' )
			),
			$resolved->getKey()
		);
		self::assertFalse( $this->ledger->markResolved( $first, $this->instantAt( '+11 minutes' ) ) );
		self::assertTrue( $this->ledger->markResolved( $current, $this->instantAt( '+11 minutes' ) ) );
		self::assertSame( DeletedPostRepairStatus::RESOLVED, $this->record( $resolved )->getStatus() );

		$transient = $this->identity( 'ledger-client-a', 111 );
		[ $transient_stale, $transient_current ] = $this->stale_and_current_leases( $transient, $storage );
		self::assertFalse( $this->ledger->deleteTransientSuccess( $transient_stale ) );
		self::assertTrue( $this->ledger->deleteTransientSuccess( $transient_current ) );
		self::assertNull( $this->ledger->findForClient( 'ledger-client-a', $transient->getKey() ) );
	}

	public function test_backward_transition_timestamps_are_rejected_without_corrupting_state(): void
	{
		$storage = new DeletedPostRepairLedgerStorageA();

		$retry = $this->identity( 'ledger-client-a', 138 );
		$retry_lease = $this->arm( $retry, $storage, '+10 minutes' );
		self::assertFalse(
			$this->ledger->markRetryWait(
				$retry_lease,
				$this->diagnostic( 'storage', 'stale retry transition' ),
				$this->instantAt( '+5 minutes' ),
				$this->instantAt( '-1 minute' )
			)
		);
		self::assertSame( DeletedPostRepairStatus::RUNNING, $this->record( $retry )->getStatus() );

		$attention = $this->identity( 'ledger-client-a', 139 );
		$attention_lease = $this->arm( $attention, $storage, '+10 minutes' );
		self::assertFalse(
			$this->ledger->markNeedsAttention(
				$attention_lease,
				$this->diagnostic( 'storage', 'stale attention transition' ),
				$this->instantAt( '-1 minute' )
			)
		);
		self::assertSame( DeletedPostRepairStatus::RUNNING, $this->record( $attention )->getStatus() );

		$resolved = $this->identity( 'ledger-client-a', 140 );
		$initial = $this->arm( $resolved, $storage, '+5 minutes' );
		self::assertTrue(
			$this->ledger->markRetryWait(
				$initial,
				$this->diagnostic( 'storage', 'recorded failure' ),
				$this->instantAt( '+10 minutes' ),
				$this->instantAt( '+1 minute' )
			)
		);
		$current = $this->assert_acquired(
			$this->ledger->tryClaimDue(
				$resolved->getKey(),
				$storage,
				$this->instantAt( '+10 minutes' ),
				$this->instantAt( '+20 minutes' )
			),
			$resolved->getKey()
		);
		self::assertFalse( $this->ledger->markResolved( $current, $this->instantAt( '+9 minutes' ) ) );
		self::assertSame( DeletedPostRepairStatus::RUNNING, $this->record( $resolved )->getStatus() );

		$wakeup = $this->identity( 'ledger-client-a', 141 );
		$this->arm( $wakeup, $storage, '+10 minutes' );
		self::assertFalse(
			$this->ledger->recordWakeupFailure(
				$wakeup->getKey(),
				$this->diagnostic( 'scheduler', 'stale wake-up transition' ),
				$this->instantAt( '-1 minute' )
			)
		);
		self::assertNull( $this->record( $wakeup )->getWakeupFailureAt() );
	}

	public function test_backward_claim_and_mismatch_timestamps_leave_retry_state_unchanged(): void
	{
		$storage = new DeletedPostRepairLedgerStorageA();
		$claim = $this->identity( 'ledger-client-a', 142 );
		$claim_lease = $this->arm( $claim, $storage, '+5 minutes' );
		self::assertTrue(
			$this->ledger->markRetryWait(
				$claim_lease,
				$this->diagnostic( 'storage', 'claim clock baseline' ),
				$this->instantAt( '+10 minutes' ),
				$this->instantAt( '+1 minute' )
			)
		);

		$claim_result = $this->ledger->tryClaimManually(
			$claim->getKey(),
			$storage,
			$this->now,
			$this->instantAt( '+5 minutes' )
		);
		self::assertSame( 'unavailable', $claim_result->getOutcome() );
		self::assertSame( DeletedPostRepairStatus::RETRY_WAIT, $this->record( $claim )->getStatus() );

		$mismatch = $this->identity( 'ledger-client-a', 143 );
		$mismatch_lease = $this->arm( $mismatch, $storage, '+5 minutes' );
		self::assertTrue(
			$this->ledger->markRetryWait(
				$mismatch_lease,
				$this->diagnostic( 'storage', 'mismatch clock baseline' ),
				$this->instantAt( '+10 minutes' ),
				$this->instantAt( '+1 minute' )
			)
		);
		$mismatch_result = $this->ledger->tryClaimManually(
			$mismatch->getKey(),
			new DeletedPostRepairLedgerStorageB(),
			$this->now,
			$this->instantAt( '+5 minutes' )
		);
		self::assertSame( 'unavailable', $mismatch_result->getOutcome() );
		self::assertSame( DeletedPostRepairStatus::RETRY_WAIT, $this->record( $mismatch )->getStatus() );
	}

	public function test_wakeup_failure_cannot_advance_a_running_record_beyond_its_lease(): void
	{
		$identity = $this->identity( 'ledger-client-a', 144 );
		$this->arm( $identity, new DeletedPostRepairLedgerStorageA(), '+5 minutes' );

		self::assertFalse(
			$this->ledger->recordWakeupFailure(
				$identity->getKey(),
				$this->diagnostic( 'scheduler', 'wake-up after lease expiry' ),
				$this->instantAt( '+6 minutes' )
			)
		);
		$record = $this->record( $identity );
		self::assertNull( $record->getWakeupFailureAt() );
		self::assertSame( DeletedPostRepairStatus::RUNNING, $record->getStatus() );
	}

	public function test_success_after_a_recorded_failure_is_resolved_not_transiently_deleted(): void
	{
		$identity = $this->identity( 'ledger-client-a', 112 );
		$storage = new DeletedPostRepairLedgerStorageA();
		$first = $this->arm( $identity, $storage, '+5 minutes' );
		self::assertTrue(
			$this->ledger->markRetryWait(
				$first,
				$this->diagnostic( 'storage', 'recorded failure' ),
				$this->instantAt( '+10 minutes' ),
				$this->instantAt( '+1 minute' )
			)
		);
		$retry = $this->assert_acquired(
			$this->ledger->tryClaimDue(
				$identity->getKey(),
				$storage,
				$this->instantAt( '+10 minutes' ),
				$this->instantAt( '+20 minutes' )
			),
			$identity->getKey()
		);

		self::assertFalse( $this->ledger->deleteTransientSuccess( $retry ) );
		self::assertNotNull( $this->ledger->findForClient( 'ledger-client-a', $identity->getKey() ) );
		self::assertTrue( $this->ledger->markResolved( $retry, $this->instantAt( '+11 minutes' ) ) );
		self::assertSame( DeletedPostRepairStatus::RESOLVED, $this->record( $identity )->getStatus() );

		$resolved_claim = $this->ledger->tryClaimManually(
			$identity->getKey(),
			$storage,
			$this->instantAt( '+12 minutes' ),
			$this->instantAt( '+22 minutes' )
		);
		self::assertSame( 'resolved', $resolved_claim->getOutcome() );
		self::assertNull( $resolved_claim->getLease() );
	}

	public function test_resolved_outcome_takes_precedence_over_runtime_adapter_mismatch(): void
	{
		$identity = $this->identity( 'ledger-client-a', 136 );
		$this->create_resolved(
			$identity,
			new DeletedPostRepairLedgerStorageA(),
			$this->instantAt( '-1 hour' )
		);

		$result = $this->ledger->tryClaimManually(
			$identity->getKey(),
			new DeletedPostRepairLedgerStorageB(),
			$this->now,
			$this->instantAt( '+10 minutes' )
		);

		self::assertSame( 'resolved', $result->getOutcome() );
		self::assertNull( $result->getLease() );
		self::assertSame( DeletedPostRepairStatus::RESOLVED, $this->record( $identity )->getStatus() );
	}

	public function test_success_without_a_recorded_failure_cannot_be_marked_resolved(): void
	{
		$identity = $this->identity( 'ledger-client-a', 132 );
		$lease = $this->arm( $identity, new DeletedPostRepairLedgerStorageA(), '+5 minutes' );

		self::assertFalse( $this->ledger->markResolved( $lease, $this->instantAt( '+1 minute' ) ) );
		$record = $this->record( $identity );
		self::assertSame( DeletedPostRepairStatus::RUNNING, $record->getStatus() );
		self::assertSame( 0, $record->getFailureCount() );
		self::assertNull( $record->getResolvedAt() );
		self::assertTrue( $this->ledger->deleteTransientSuccess( $lease ) );
		self::assertNull( $this->record_or_null( $identity ) );
	}

	public function test_failure_and_wakeup_diagnostics_are_bounded_safe_and_do_not_change_identity(): void
	{
		$identity = $this->identity( 'ledger-client-a', 113 );
		$storage = new DeletedPostRepairLedgerStorageA();
		$lease = $this->arm( $identity, $storage, '+5 minutes' );
		$unsafe_summary = "SELECT * FROM wp_private WHERE password='secret-value';\n" . str_repeat( 'x', 2048 );
		$failure = new DeletedPostRepairDiagnostic(
			str_repeat( 'storage', 20 ) . "\0",
			"Vendor\\Failure@anonymous\0/private/path.php:123",
			str_repeat( 'E', 128 ) . "\n",
			$unsafe_summary
		);
		self::assertTrue(
			$this->ledger->markRetryWait(
				$lease,
				$failure,
				$this->instantAt( '+10 minutes' ),
				$this->instantAt( '+1 minute' )
			)
		);

		$wakeup = new DeletedPostRepairDiagnostic(
			str_repeat( 'scheduler', 20 ),
			"RuntimeException\0/private/scheduler.php",
			str_repeat( 'W', 128 ),
			"Authorization: Bearer secret-token\n" . str_repeat( 'y', 2048 )
		);
		self::assertTrue(
			$this->ledger->recordWakeupFailure( $identity->getKey(), $wakeup, $this->instantAt( '+2 minutes' ) )
		);

		$record = $this->record( $identity );
		$this->assert_identity_same( $identity, $record->getIdentity() );
		$this->assert_safe_diagnostic( $record->getFailureDiagnostic() );
		$this->assert_safe_diagnostic( $record->getWakeupDiagnostic() );
		self::assertStringNotContainsString( 'SELECT *', $record->getFailureDiagnostic()->getSummary() );
		self::assertStringNotContainsString( 'secret-value', $record->getFailureDiagnostic()->getSummary() );
		self::assertStringNotContainsString( 'Bearer', $record->getWakeupDiagnostic()->getSummary() );
		self::assertStringNotContainsString( 'secret-token', $record->getWakeupDiagnostic()->getSummary() );
		self::assertSame( 1, $record->getAttemptCount() );
		self::assertSame( 1, $record->getFailureCount() );
	}

	public function test_purge_removes_only_resolved_records_older_than_cutoff_and_honors_limit(): void
	{
		$storage = new DeletedPostRepairLedgerStorageA();
		$old_a = $this->identity( 'ledger-client-a', 114 );
		$old_b = $this->identity( 'ledger-client-a', 115 );
		$new = $this->identity( 'ledger-client-a', 116 );
		$unresolved = $this->identity( 'ledger-client-a', 117 );

		$this->create_resolved( $old_a, $storage, $this->instantAt( '-40 days' ) );
		$this->create_resolved( $old_b, $storage, $this->instantAt( '-35 days' ) );
		$this->create_resolved( $new, $storage, $this->instantAt( '-5 days' ) );
		$unresolved_lease = $this->arm_at(
			$unresolved,
			$storage,
			$this->instantAt( '-40 days' ),
			$this->instantAt( '-39 days' )
		);
		self::assertTrue(
			$this->ledger->markRetryWait(
				$unresolved_lease,
				$this->diagnostic( 'storage', 'must not purge' ),
				$this->instantAt( '-39 days' ),
				$this->instantAt( '-39 days' )
			)
		);

		self::assertSame( 1, $this->ledger->purgeResolvedBefore( $this->instantAt( '-30 days' ), 1 ) );
		$remaining_old = array_filter(
			[ $old_a, $old_b ],
			fn( DeletedPostRepairIdentity $item ): bool => null !== $this->record_or_null( $item )
		);
		self::assertCount( 1, $remaining_old );
		self::assertNotNull( $this->record_or_null( $new ) );
		self::assertNotNull( $this->record_or_null( $unresolved ) );

		self::assertSame( 1, $this->ledger->purgeResolvedBefore( $this->instantAt( '-30 days' ), 10 ) );
		self::assertNull( $this->record_or_null( $old_a ) );
		self::assertNull( $this->record_or_null( $old_b ) );
		self::assertNotNull( $this->record_or_null( $new ) );
		self::assertNotNull( $this->record_or_null( $unresolved ) );
	}

	public function test_purge_does_not_delete_a_malformed_resolved_row_without_failure_history(): void
	{
		global $wpdb;

		$identity = $this->identity( 'ledger-client-a', 137 );
		$this->create_resolved(
			$identity,
			new DeletedPostRepairLedgerStorageA(),
			$this->instantAt( '-40 days' )
		);
		self::assertNotFalse(
			$wpdb->update(
				$this->table,
				[ 'failure_count' => 0 ],
				[ 'repair_key' => $identity->getKey() ]
			)
		);

		self::assertSame( 0, $this->ledger->purgeResolvedBefore( $this->instantAt( '-30 days' ), 10 ) );
		self::assertSame(
			'1',
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `{$this->table}` WHERE `repair_key` = %s",
					$identity->getKey()
				)
			)
		);
	}

	public function test_purge_fails_closed_before_deleting_a_malformed_resolved_record(): void
	{
		global $wpdb;

		$identity = $this->identity( 'ledger-client-a', 145 );
		$this->create_resolved(
			$identity,
			new DeletedPostRepairLedgerStorageA(),
			$this->instantAt( '-40 days' )
		);
		self::assertNotFalse(
			$wpdb->update(
				$this->table,
				[ 'failure_summary' => null ],
				[ 'repair_key' => $identity->getKey() ]
			)
		);

		$failure = $this->capture_failure(
			fn() => $this->ledger->purgeResolvedBefore( $this->instantAt( '-30 days' ), 10 )
		);

		self::assertInstanceOf( StorageFailure::class, $failure );
		self::assertSame(
			'1',
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `{$this->table}` WHERE `repair_key` = %s",
					$identity->getKey()
				)
			)
		);
	}

	public function test_listing_and_lookup_are_client_scoped_status_filtered_and_keyset_paginated(): void
	{
		$storage = new DeletedPostRepairLedgerStorageA();
		$client_a = [
			$this->identity( 'ledger-client-a', 118 ),
			$this->identity( 'ledger-client-a', 119 ),
			$this->identity( 'ledger-client-a', 120 ),
		];
		$client_b = $this->identity( 'ledger-client-b', 121 );

		foreach ( [ ...$client_a, $client_b ] as $offset => $identity ) {
			$lease = $this->arm( $identity, $storage, '+5 minutes' );
			self::assertTrue(
				$this->ledger->markRetryWait(
					$lease,
					$this->diagnostic( 'storage', 'list failure ' . $offset ),
					$this->instantAt( '+10 minutes' ),
					$this->instantAt( '+1 minute' )
				)
			);
		}

		$expected_keys = array_map(
			static fn( DeletedPostRepairIdentity $identity ): string => $identity->getKey(),
			$client_a
		);
		sort( $expected_keys, SORT_STRING );
		$first_page = $this->ledger->listForClient( 'ledger-client-a', null, null, 2 );
		self::assertCount( 2, $first_page );
		self::assertSame( array_slice( $expected_keys, 0, 2 ), $this->record_keys( $first_page ) );
		$second_page = $this->ledger->listForClient(
			'ledger-client-a',
			null,
			$expected_keys[1],
			2
		);
		self::assertSame( array_slice( $expected_keys, 2 ), $this->record_keys( $second_page ) );

		$retry_wait = $this->ledger->listForClient(
			'ledger-client-a',
			DeletedPostRepairStatus::RETRY_WAIT,
			null,
			10
		);
		self::assertSame( $expected_keys, $this->record_keys( $retry_wait ) );
		self::assertNull( $this->ledger->findForClient( 'ledger-client-b', $client_a[0]->getKey() ) );
		self::assertNull( $this->ledger->findForClient( 'ledger-client-a', $client_b->getKey() ) );
		self::assertNotNull( $this->ledger->findForClient( 'ledger-client-b', $client_b->getKey() ) );
	}

	public function test_due_listing_returns_due_retry_wait_and_expired_running_only(): void
	{
		$storage = new DeletedPostRepairLedgerStorageA();
		$due = $this->identity( 'ledger-client-a', 122 );
		$future = $this->identity( 'ledger-client-a', 123 );
		$expired = $this->identity( 'ledger-client-b', 124 );
		$attention = $this->identity( 'ledger-client-b', 125 );

		$due_lease = $this->arm( $due, $storage, '+5 minutes' );
		self::assertTrue(
			$this->ledger->markRetryWait(
				$due_lease,
				$this->diagnostic( 'storage', 'due' ),
				$this->instantAt( '+10 minutes' ),
				$this->instantAt( '+1 minute' )
			)
		);
		$future_lease = $this->arm( $future, $storage, '+5 minutes' );
		self::assertTrue(
			$this->ledger->markRetryWait(
				$future_lease,
				$this->diagnostic( 'storage', 'future' ),
				$this->instantAt( '+30 minutes' ),
				$this->instantAt( '+1 minute' )
			)
		);
		$this->arm( $expired, $storage, '+5 minutes' );
		$attention_lease = $this->arm( $attention, $storage, '+5 minutes' );
		self::assertTrue(
			$this->ledger->markNeedsAttention(
				$attention_lease,
				$this->diagnostic( 'storage', 'attention' ),
				$this->instantAt( '+1 minute' )
			)
		);

		$due_records = $this->ledger->findDue( 10, $this->instantAt( '+10 minutes' ) );
		$due_keys = $this->record_keys( $due_records );
		sort( $due_keys, SORT_STRING );
		$expected = [ $due->getKey(), $expired->getKey() ];
		sort( $expected, SORT_STRING );
		self::assertSame( $expected, $due_keys );
	}

	/**
	 * @dataProvider foreign_site_identity_provider
	 */
	public function test_foreign_site_identity_is_rejected_before_ledger_mutation(
		int $site_id_delta,
		string $prefix_suffix
	): void {
		global $wpdb;
		$identity = new DeletedPostRepairIdentity(
			get_current_blog_id() + $site_id_delta,
			$wpdb->prefix . $prefix_suffix,
			'ledger-client-a',
			self::OPERATION,
			126
		);
		$queries = [];
		$recorder = function ( string $query ) use ( &$queries ): string {
			if ( false !== strpos( $query, $this->table ) ) {
				$queries[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $recorder );
		try {
			$failure = $this->capture_failure(
				fn() => $this->ledger->armAndTryClaim(
					$identity,
					new DeletedPostRepairLedgerStorageA(),
					$this->now,
					$this->instantAt( '+10 minutes' )
				)
			);
		} finally {
			remove_filter( 'query', $recorder );
		}

		self::assertInstanceOf( StorageFailure::class, $failure );
		self::assertSame( [], $this->ledger_mutation_queries( $queries ) );
	}

	public function foreign_site_identity_provider(): array
	{
		return [
			'foreign site ID' => [ 1, '' ],
			'foreign prefix'  => [ 0, '2_' ],
		];
	}

	public function test_false_mutation_result_throws_attributable_storage_failure(): void
	{
		global $wpdb;
		$intercepted = false;
		$block_insert = function ( string $query ) use ( &$intercepted ): string {
			if ( $this->is_ledger_mutation_query( $query ) ) {
				$intercepted = true;
				return "INSERT INTO `{$this->table}` (`missing_test_column`) VALUES (1)";
			}
			return $query;
		};
		add_filter( 'query', $block_insert );
		$suppress_errors = $wpdb->suppress_errors();
		try {
			$failure = $this->capture_failure(
				fn() => $this->ledger->armAndTryClaim(
					$this->identity( 'ledger-client-a', 127 ),
					new DeletedPostRepairLedgerStorageA(),
					$this->now,
					$this->instantAt( '+10 minutes' )
				)
			);
		} finally {
			$wpdb->suppress_errors( $suppress_errors );
			remove_filter( 'query', $block_insert );
		}

		self::assertTrue( $intercepted, 'The ledger INSERT fault was not injected.' );
		self::assertInstanceOf( StorageFailure::class, $failure );
		self::assertStringContainsString( 'repair', strtolower( $failure->getOperation() ) );
	}

	public function test_malformed_read_result_throws_attributable_storage_failure(): void
	{
		$identity = $this->identity( 'ledger-client-a', 128 );
		$this->arm( $identity, new DeletedPostRepairLedgerStorageA(), '+10 minutes' );
		$intercepted = false;
		$malform_read = function ( string $query ) use ( &$intercepted ): string {
			if ( $this->is_ledger_query( $query, 'SELECT' ) ) {
				$intercepted = true;
				return "SELECT 'malformed-ledger-row' AS unexpected";
			}
			return $query;
		};
		add_filter( 'query', $malform_read );
		try {
			$failure = $this->capture_failure(
				fn() => $this->ledger->findForClient( 'ledger-client-a', $identity->getKey() )
			);
		} finally {
			remove_filter( 'query', $malform_read );
		}

		self::assertTrue( $intercepted, 'The malformed ledger SELECT was not injected.' );
		self::assertInstanceOf( StorageFailure::class, $failure );
		self::assertStringContainsString( 'repair', strtolower( $failure->getOperation() ) );
	}

	/**
	 * @dataProvider malformed_persisted_state_provider
	 */
	public function test_malformed_persisted_state_fails_closed( array $mutation ): void
	{
		global $wpdb;

		$identity = $this->identity( 'ledger-client-a', 133 );
		$this->arm( $identity, new DeletedPostRepairLedgerStorageA(), '+10 minutes' );
		self::assertNotFalse(
			$wpdb->update(
				$this->table,
				$mutation,
				[ 'repair_key' => $identity->getKey() ]
			)
		);

		$failure = $this->capture_failure(
			fn() => $this->ledger->findForClient( 'ledger-client-a', $identity->getKey() )
		);

		self::assertInstanceOf( StorageFailure::class, $failure );
		self::assertStringContainsString( 'repair', strtolower( $failure->getOperation() ) );
	}

	public function malformed_persisted_state_provider(): array
	{
		$diagnostic = [
			'failure_count'    => 1,
			'failure_category' => 'storage',
			'failure_class'    => StorageFailure::class,
			'failure_code'     => '311',
			'failure_summary'  => '[diagnostic details redacted]',
			'first_failure_at' => '2026-09-14 00:00:00',
			'last_failure_at'  => '2026-09-14 00:00:00',
		];

		return [
			'running row without a lease token' => [ [ 'lease_token' => null ] ],
			'partial failure diagnostic'        => [ [ 'failure_class' => StorageFailure::class ] ],
			'noncanonical adapter label'        => [ [ 'storage_class' => 'printable secret path' ] ],
			'expired lease before last update'  => [ [ 'lease_expires_at' => '2026-09-13 23:59:59' ] ],
			'retry before its recorded failure' => [
				[
					...$diagnostic,
					'status'            => DeletedPostRepairStatus::RETRY_WAIT,
					'next_attempt_at'   => '2026-09-13 23:59:59',
					'lease_token'       => null,
					'lease_expires_at'  => null,
				]
			],
			'failure before record creation'    => [
				[
					...$diagnostic,
					'first_failure_at' => '2026-09-13 23:59:59',
				]
			],
			'resolution after last update'      => [
				[
					...$diagnostic,
					'status'           => DeletedPostRepairStatus::RESOLVED,
					'lease_token'      => null,
					'lease_expires_at' => null,
					'resolved_at'      => '2026-09-14 00:00:01',
				]
			],
		];
	}

	public function test_mutation_does_not_attempt_ddl_after_ledger_table_is_removed(): void
	{
		global $wpdb;
		$wpdb->query( "DROP TABLE `{$this->table}`" );
		self::assertFalse( $this->table_exists() );
		$queries = [];
		$recorder = function ( string $query ) use ( &$queries ): string {
			if ( false !== strpos( $query, $this->table ) ) {
				$queries[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $recorder );
		$suppress_errors = $wpdb->suppress_errors();
		try {
			$failure = $this->capture_failure(
				fn() => $this->ledger->armAndTryClaim(
					$this->identity( 'ledger-client-a', 129 ),
					new DeletedPostRepairLedgerStorageA(),
					$this->now,
					$this->instantAt( '+10 minutes' )
				)
			);
		} finally {
			$wpdb->suppress_errors( $suppress_errors );
			remove_filter( 'query', $recorder );
		}

		self::assertInstanceOf( StorageFailure::class, $failure );
		self::assertSame( [], $this->ledger_ddl_queries( $queries ) );
		self::assertFalse( $this->table_exists() );
	}

	private function identity( string $client_name, int $post_id ): DeletedPostRepairIdentity
	{
		global $wpdb;

		return new DeletedPostRepairIdentity(
			get_current_blog_id(),
			(string) $wpdb->prefix,
			$client_name,
			self::OPERATION,
			$post_id
		);
	}

	private function anonymous_storage(): object
	{
		return new class() {
		};
	}

	private function arm(
		DeletedPostRepairIdentity $identity,
		object $storage,
		string $lease_until
	): DeletedPostRepairLease {
		return $this->arm_at( $identity, $storage, $this->now, $this->instantAt( $lease_until ) );
	}

	private function arm_at(
		DeletedPostRepairIdentity $identity,
		object $storage,
		DateTimeImmutable $now,
		DateTimeImmutable $lease_until
	): DeletedPostRepairLease {
		return $this->assert_acquired(
			$this->ledger->armAndTryClaim(
				$identity,
				$storage,
				$now,
				$lease_until
			),
			$identity->getKey()
		);
	}

	/**
	 * @return array{0: DeletedPostRepairLease, 1: DeletedPostRepairLease}
	 */
	private function stale_and_current_leases(
		DeletedPostRepairIdentity $identity,
		object $storage
	): array {
		$stale = $this->arm( $identity, $storage, '+5 minutes' );
		$current = $this->assert_acquired(
			$this->ledger->tryClaimDue(
				$identity->getKey(),
				$storage,
				$this->instantAt( '+10 minutes' ),
				$this->instantAt( '+20 minutes' )
			),
			$identity->getKey()
		);

		return [ $stale, $current ];
	}

	private function create_resolved(
		DeletedPostRepairIdentity $identity,
		object $storage,
		DateTimeImmutable $resolved_at
	): void {
		$initial_at = $resolved_at->sub( new DateInterval( 'PT2H' ) );
		$retry_at = $resolved_at->sub( new DateInterval( 'PT1H' ) );
		$initial = $this->assert_acquired(
			$this->ledger->armAndTryClaim(
				$identity,
				$storage,
				$initial_at,
				$initial_at->add( new DateInterval( 'PT10M' ) )
			),
			$identity->getKey()
		);
		self::assertTrue(
			$this->ledger->markNeedsAttention(
				$initial,
				$this->diagnostic( 'storage', 'historical failure' ),
				$initial_at->add( new DateInterval( 'PT1M' ) )
			)
		);
		$retry = $this->assert_acquired(
			$this->ledger->tryClaimManually(
				$identity->getKey(),
				$storage,
				$retry_at,
				$retry_at->add( new DateInterval( 'PT10M' ) )
			),
			$identity->getKey()
		);
		self::assertTrue( $this->ledger->markResolved( $retry, $resolved_at ) );
	}

	private function diagnostic( string $category, string $summary ): DeletedPostRepairDiagnostic
	{
		return new DeletedPostRepairDiagnostic( $category, StorageFailure::class, '311', $summary );
	}

	private function assert_acquired(
		DeletedPostRepairClaimResult $result,
		string $repair_key
	): DeletedPostRepairLease {
		self::assertSame( 'acquired', $result->getOutcome() );
		$lease = $result->getLease();
		self::assertInstanceOf( DeletedPostRepairLease::class, $lease );
		self::assertSame( $repair_key, $lease->getRepairKey() );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/D', $lease->getToken() );

		return $lease;
	}

	private function require_record( string $client_name, string $repair_key ): DeletedPostRepairRecord
	{
		$record = $this->ledger->findForClient( $client_name, $repair_key );
		self::assertInstanceOf( DeletedPostRepairRecord::class, $record );

		return $record;
	}

	private function record( DeletedPostRepairIdentity $identity ): DeletedPostRepairRecord
	{
		return $this->require_record( $identity->getClientName(), $identity->getKey() );
	}

	private function record_or_null( DeletedPostRepairIdentity $identity ): ?DeletedPostRepairRecord
	{
		return $this->ledger->findForClient( $identity->getClientName(), $identity->getKey() );
	}

	private function assert_identity_same(
		DeletedPostRepairIdentity $expected,
		DeletedPostRepairIdentity $actual
	): void {
		self::assertSame( $expected->getKey(), $actual->getKey() );
		self::assertSame( $expected->getSiteId(), $actual->getSiteId() );
		self::assertSame( $expected->getSitePrefix(), $actual->getSitePrefix() );
		self::assertSame( $expected->getClientName(), $actual->getClientName() );
		self::assertSame( $expected->getOperation(), $actual->getOperation() );
		self::assertSame( $expected->getPostId(), $actual->getPostId() );
	}

	private function assert_safe_diagnostic( ?DeletedPostRepairDiagnostic $diagnostic ): void
	{
		self::assertInstanceOf( DeletedPostRepairDiagnostic::class, $diagnostic );
		self::assertLessThanOrEqual( 64, strlen( $diagnostic->getCategory() ) );
		self::assertLessThanOrEqual( 191, strlen( $diagnostic->getClass() ) );
		self::assertLessThanOrEqual( 64, strlen( $diagnostic->getCode() ) );
		self::assertLessThanOrEqual( 512, strlen( $diagnostic->getSummary() ) );
		foreach (
			[
				$diagnostic->getCategory(),
				$diagnostic->getClass(),
				$diagnostic->getCode(),
				$diagnostic->getSummary(),
			] as $value
		) {
			self::assertSame( 0, preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value ) );
		}
	}

	/**
	 * @param DeletedPostRepairRecord[] $records
	 * @return string[]
	 */
	private function record_keys( array $records ): array
	{
		return array_map(
			static function ( DeletedPostRepairRecord $record ): string {
				return $record->getIdentity()->getKey();
			},
			$records
		);
	}

	private function instantAt( string $modifier ): DateTimeImmutable
	{
		return $this->now->modify( $modifier );
	}

	private function capture_failure( callable $operation ): ?Throwable
	{
		try {
			$operation();
		} catch ( Throwable $failure ) {
			return $failure;
		}

		return null;
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

	private function is_ledger_query( string $query, string $operation ): bool
	{
		return 1 === preg_match( '/^\s*' . preg_quote( $operation, '/' ) . '\b/i', $query ) &&
			false !== strpos( $query, $this->table );
	}

	private function is_ledger_mutation_query( string $query ): bool
	{
		return 1 === preg_match( '/^\s*(INSERT|UPDATE|REPLACE)\b/i', $query ) &&
			false !== strpos( $query, $this->table );
	}

	/**
	 * @param string[] $queries
	 * @return string[]
	 */
	private function ledger_mutation_queries( array $queries ): array
	{
		return array_values(
			array_filter(
				$queries,
				function ( string $query ): bool {
					return false !== strpos( $query, $this->table ) &&
						1 === preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE)\b/i', $query );
				}
			)
		);
	}

	/**
	 * @param string[] $queries
	 * @return string[]
	 */
	private function ledger_ddl_queries( array $queries ): array
	{
		return array_values(
			array_filter(
				$queries,
				function ( string $query ): bool {
					return false !== strpos( $query, $this->table ) &&
						1 === preg_match(
							'/^\s*(?:CREATE(?:\s+TEMPORARY)?|ALTER|DROP(?:\s+TEMPORARY)?|RENAME|TRUNCATE)\s+TABLE\b/i',
							$query
						);
				}
			)
		);
	}

	/**
	 * @param string[] $queries
	 * @return string[]
	 */
	private function transaction_control_queries( array $queries ): array
	{
		return array_values(
			array_filter(
				$queries,
				static function ( string $query ): bool {
					return 1 === preg_match(
						'/^\s*(?:START\s+TRANSACTION|COMMIT|ROLLBACK|SAVEPOINT|RELEASE\s+SAVEPOINT)\b/i',
						$query
					);
				}
			)
		);
	}

	/**
	 * @param string[] $queries
	 * @return string[]
	 */
	private function connection_table_dml( array $queries ): array
	{
		return array_values(
			array_filter(
				$queries,
				static function ( string $query ): bool {
					return 1 === preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE)\b/i', $query ) &&
						(
							false !== strpos( $query, 'post_connections_' ) ||
							false !== strpos( $query, 'post_connections_meta_' )
						) &&
						false === strpos( $query, self::TABLE_BASENAME );
				}
			)
		);
	}

	private function table_exists(): bool
	{
		global $wpdb;

		return $this->table === $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $this->table ) )
		);
	}

	private function drop_ledger_artifacts(): void
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
				static fn( string $table_key ): bool => self::TABLE_BASENAME !== $table_key
			)
		);
	}

	public function preserve_real_repair_ledger_table( string $query ): string
	{
		$table = preg_quote( $this->table, '/' );
		$query = (string) preg_replace(
			'/^CREATE\s+TEMPORARY\s+TABLE\s+`' . $table . '`/i',
			'CREATE TABLE `' . $this->table . '`',
			$query
		);

		return (string) preg_replace(
			'/^DROP\s+TEMPORARY\s+TABLE(\s+IF\s+EXISTS)?\s+`' . $table . '`/i',
			'DROP TABLE$1 `' . $this->table . '`',
			$query
		);
	}
}
