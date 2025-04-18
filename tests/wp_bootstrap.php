<?php

declare(strict_types=1);

const UNIT_TESTS = true;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Define constants for the IDE. Never fired.
if ( defined( 'PHPSTORM_META' ) ) {
	define( 'DOING_TESTS', 1 );
	define( 'CLIENT_NAME', 'client-test' );
	define( 'RELATION_0_NAME', 'relation-0-test' );
	define( 'RELATION_1_NAME', 'relation-1-test' );
}

use iTRON\wpConnections\Helpers\Database;
use iTRON\wpConnections\WPStorage;

$test_root = getenv( 'WP_TESTS_DIR' ) ? : dirname( __FILE__ ) . '/../wordpress-develop/tests/phpunit';
require_once $test_root . '/includes/functions.php';

tests_add_filter( 'muplugins_loaded', function() {
	// Remove tables created by WPC Client.
	global $wpdb;

	$q = "DROP TABLE IF EXISTS " . $wpdb->prefix .
	     WPStorage::CONNECTIONS_TABLE_PREFIX .
	     Database::normalize_table_name( CLIENT_NAME );

	$wpdb->query( $q );

	$q = "DROP TABLE IF EXISTS " . $wpdb->prefix .
	     WPStorage::META_TABLE_PREFIX .
	     Database::normalize_table_name( CLIENT_NAME );

	$wpdb->query( $q );
} );

tests_add_filter( 'wpConnections/storage/installOnInit', function ( $installOnInit ) {
	return true;
}, 10, 1 );

require_once $test_root . '/includes/bootstrap.php';
