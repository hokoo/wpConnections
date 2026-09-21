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

	// The WordPress test base rewrites DDL to temporary tables. Repair-ledger
	// readiness intentionally uses information_schema, where temporary-table
	// visibility differs between supported MySQL and MariaDB versions. Keep
	// this one production-owned table real throughout every integration test.
	add_filter(
		'query',
		static function ( string $query ): string {
			$query = (string) preg_replace(
				'/^CREATE\s+TEMPORARY\s+TABLE\s+`([A-Za-z0-9_]*wpconnections_repair)`/i',
				'CREATE TABLE `$1`',
				$query
			);

			return (string) preg_replace(
				'/^DROP\s+TEMPORARY\s+TABLE(\s+IF\s+EXISTS)?\s+`([A-Za-z0-9_]*wpconnections_repair)`/i',
				'DROP TABLE$1 `$2`',
				$query
			);
		},
		11
	);
} );

require_once $test_root . '/includes/bootstrap.php';
