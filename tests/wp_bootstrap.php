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
	// Mark the original permanent DDL before the core priority-10 rewrite so the
	// restoring filter cannot hide a production CREATE TEMPORARY regression or
	// rewrite a deliberately temporary isolation fixture. A per-process nonce in
	// the query itself keeps provenance stateless and safe under nested queries.
	$repair_table_pattern = '[A-Za-z0-9_]+wpconnections_repair';
	$repair_ddl_marker    = ' /* wpconnections-test-real-repair-ddl:' . bin2hex( random_bytes( 16 ) ) . ' */';
	add_filter(
		'query',
		static function ( string $query ) use ( $repair_ddl_marker, $repair_table_pattern ): string {
			if ( 1 !== preg_match(
				'/^(?:CREATE\s+TABLE|DROP\s+TABLE(?:\s+IF\s+EXISTS)?)\s+`' . $repair_table_pattern . '`/i',
				$query
			) ) {
				return $query;
			}

			return $query . $repair_ddl_marker;
		},
		9
	);
	add_filter(
		'query',
		static function ( string $query ) use ( $repair_ddl_marker, $repair_table_pattern ): string {
			$marker_length = strlen( $repair_ddl_marker );
			if ( $repair_ddl_marker !== substr( $query, -$marker_length ) ) {
				return $query;
			}
			$query = substr( $query, 0, -$marker_length );

			$query = (string) preg_replace(
				'/^CREATE\s+TEMPORARY\s+TABLE\s+`(' . $repair_table_pattern . ')`/i',
				'CREATE TABLE `$1`',
				$query
			);

			return (string) preg_replace(
				'/^DROP\s+TEMPORARY\s+TABLE(\s+IF\s+EXISTS)?\s+`(' . $repair_table_pattern . ')`/i',
				'DROP TABLE$1 `$2`',
				$query
			);
		},
		11
	);
} );

require_once $test_root . '/includes/bootstrap.php';
