<?php
// Drop the database tables.
// Remove tables created by WPC Client.
global $wpdb;

use iTRON\wpConnections\Helpers\Database;
use iTRON\wpConnections\WPStorage;

$client_name = $argv[1];

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../wordpress-develop/wp-tests-config.php';
require_once ABSPATH . 'wp-settings.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-includes/class-wpdb.php';



$wpdb->select( DB_NAME, $wpdb->dbh );

$q = 		"DROP TABLE IF EXISTS " . $wpdb->prefix .
            WPStorage::CONNECTIONS_TABLE_PREFIX .
            Database::normalize_table_name( $client_name );

$result = $wpdb->query( $q );

echo PHP_EOL . "QUERY: " . $q . PHP_EOL;
echo "Dropped tables: ";
var_dump( $result );
echo PHP_EOL;

// Close transaction.
$wpdb->query( 'COMMIT;' );

//	exit;
