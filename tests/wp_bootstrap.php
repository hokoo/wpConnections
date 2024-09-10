<?php

use iTRON\wpConnections\Helpers\Database;
use iTRON\wpConnections\Tests\iTRON\wpConnections\WP\WPUnitTest;
use iTRON\wpConnections\WPStorage;

require dirname( __FILE__ ) . '/../wordpress-develop/tests/phpunit/includes/functions.php';

tests_add_filter( 'muplugins_loaded', function() {
	// Remove tables created by WPC Client.
	global $wpdb;

	$q = "DROP TABLE IF EXISTS " . $wpdb->prefix .
	     WPStorage::CONNECTIONS_TABLE_PREFIX .
	     Database::normalize_table_name( WPUnitTest::CLIENT_NAME );

	$wpdb->query( $wpdb->prepare( $q ) );

	$q = "DROP TABLE IF EXISTS " . $wpdb->prefix .
	     WPStorage::META_TABLE_PREFIX .
	     Database::normalize_table_name( WPUnitTest::CLIENT_NAME );

	$wpdb->query( $wpdb->prepare( $q ) );
} );

require dirname( __FILE__ ) . "/../wordpress-develop/tests/phpunit/includes/bootstrap.php";
