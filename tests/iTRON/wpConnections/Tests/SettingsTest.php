<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\Tests;

use iTRON\wpConnections\DebugLogObserver;
use iTRON\wpConnections\Settings;
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase
{
	public function test_logging_observer_is_not_registered_when_wp_debug_is_disabled(): void
	{
		if ( defined( 'WP_DEBUG' ) ) {
			self::assertTrue( WP_DEBUG );
			return;
		}

		define( 'WP_DEBUG', false );
		self::assertFalse( WP_DEBUG );
		self::assertFalse( class_exists( DebugLogObserver::class, false ) );

		( new Settings() )->init();

		self::assertFalse( class_exists( DebugLogObserver::class, false ) );
	}
}
