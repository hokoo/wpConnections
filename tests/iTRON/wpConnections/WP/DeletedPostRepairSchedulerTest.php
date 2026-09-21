<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use DateTimeImmutable;
use DateTimeZone;
use iTRON\wpConnections\Internal\WordPressDeletedPostRepairScheduler;
use RuntimeException;
use WP_Error;

class DeletedPostRepairSchedulerTest extends \WP_UnitTestCase
{
	public function set_up()
	{
		parent::set_up();
		wp_clear_scheduled_hook( WordPressDeletedPostRepairScheduler::EVENT_HOOK );
	}

	public function tear_down()
	{
		wp_clear_scheduled_hook( WordPressDeletedPostRepairScheduler::EVENT_HOOK );
		parent::tear_down();
	}

	public function test_native_adapter_persists_only_the_stable_empty_argument_site_event(): void
	{
		$scheduler = new WordPressDeletedPostRepairScheduler();
		$at = new DateTimeImmutable( '+1 hour', new DateTimeZone( 'UTC' ) );

		self::assertFalse( has_action( WordPressDeletedPostRepairScheduler::EVENT_HOOK ) );
		$scheduler->scheduleWakeup( $at );
		$event = wp_get_scheduled_event( WordPressDeletedPostRepairScheduler::EVENT_HOOK, [] );
		self::assertIsObject( $event );
		self::assertSame( WordPressDeletedPostRepairScheduler::EVENT_HOOK, $event->hook );
		self::assertSame( [], $event->args );
		self::assertSame( $at->getTimestamp(), $event->timestamp );
		self::assertSame( $at->getTimestamp(), $scheduler->nextWakeupAt()->getTimestamp() );
		self::assertFalse( has_action( WordPressDeletedPostRepairScheduler::EVENT_HOOK ) );

		$scheduler->unscheduleWakeup( $at );
		self::assertFalse( wp_next_scheduled( WordPressDeletedPostRepairScheduler::EVENT_HOOK, [] ) );
	}

	public function test_native_schedule_error_is_safe_and_does_not_create_an_event(): void
	{
		$failure = static function ( $pre, $event, bool $wpError ) {
			unset( $pre, $event, $wpError );

			return new WP_Error( 'blocked', 'private cron option payload' );
		};
		add_filter( 'pre_schedule_event', $failure, 10, 3 );
		try {
			$scheduler = new WordPressDeletedPostRepairScheduler();
			try {
				$scheduler->scheduleWakeup(
					new DateTimeImmutable( '+1 hour', new DateTimeZone( 'UTC' ) )
				);
				self::fail( 'A WordPress scheduler error must fail.' );
			} catch ( RuntimeException $exception ) {
				self::assertStringNotContainsString( 'private cron', $exception->getMessage() );
			}
		} finally {
			remove_filter( 'pre_schedule_event', $failure, 10 );
		}

		self::assertFalse( wp_next_scheduled( WordPressDeletedPostRepairScheduler::EVENT_HOOK, [] ) );
	}

	public function test_dispatch_availability_reports_disabled_wp_cron_without_affecting_storage_api(): void
	{
		self::assertSame(
			! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
			( new WordPressDeletedPostRepairScheduler() )->isAutomaticDispatchAvailable()
		);
	}
}
