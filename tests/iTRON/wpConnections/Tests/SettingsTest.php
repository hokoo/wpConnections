<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\Tests;

use iTRON\wpConnections\DebugLogObserver;
use iTRON\wpConnections\Settings;
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase
{
	public function test_logging_observer_is_not_registered_when_wp_debug_is_disabled(): void
	{
		if ( function_exists( 'add_action' ) ) {
			self::assertTrue( defined( 'WP_DEBUG' ) && WP_DEBUG );
			return;
		}

		if ( ! defined( 'WP_DEBUG' ) ) {
			define( 'WP_DEBUG', false );
		}
		self::assertFalse( WP_DEBUG );
		self::assertFalse( class_exists( DebugLogObserver::class, false ) );

		( new Settings() )->init();

		self::assertFalse( class_exists( DebugLogObserver::class, false ) );
	}
}
