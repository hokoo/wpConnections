<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Abstracts\Connection as AbstractConnection;
use iTRON\wpConnections\Abstracts\Storage;
use iTRON\wpConnections\Client;
use iTRON\wpConnections\ConnectionCollection;
use iTRON\wpConnections\Exceptions\ClientRegisterFail;
use iTRON\wpConnections\MetaCollection;
use iTRON\wpConnections\Query\Connection as ConnectionQuery;
use iTRON\wpConnections\Query\MetaCollection as MetaQueryCollection;
use iTRON\wpConnections\WPStorage;

class ClientIsolationMemoryStorage extends Storage
{
	public function __construct( Client $client )
	{
	}

	public function createConnection( ConnectionQuery $connection_query ): int
	{
		return 1;
	}

	public function updateConnection( AbstractConnection $connection ): bool
	{
		return true;
	}

	public function deleteSpecificConnections( $connection_ids ): int
	{
		return 0;
	}

	public function deleteByObjectID(
		$object_ids,
		string $relation = '',
		bool $only_from = false,
		bool $only_to = false
	): int {
		return 0;
	}

	public function deleteDirectedConnections(
		?int $from = null,
		?int $to = null,
		string $relation = ''
	): int {
		return 0;
	}

	public function findConnections( ConnectionQuery $params ): ConnectionCollection
	{
		return new ConnectionCollection();
	}

	public function addConnectionMeta( int $object_id, MetaCollection $meta_collection ): void
	{
	}

	public function removeConnectionMeta( int $object_id, MetaQueryCollection $meta_query )
	{
		return 0;
	}
}

/**
 * CORE-06 critical-scenario evidence:
 *
 * - CLIENT-NAME-01: canonical logical validation precedes factory selection;
 *   concrete table collision/length checks precede table side effects.
 * - CLIENT-ISO-01: a default storage instance is site-prefix bound while a
 *   custom non-table adapter receives logical validation only.
 */
class ClientIsolationTest extends \WP_UnitTestCase
{
	private string $original_prefix;
	private array $original_tables = [];
	private array $original_option_names = [];
	private array $clients = [];
	private array $physical_tables = [];

	public function set_up()
	{
		parent::set_up();

		global $wpdb;
		$this->original_prefix       = $wpdb->prefix;
		$this->original_tables       = $wpdb->tables;
		$this->original_option_names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options}" );
	}

	public function tear_down()
	{
		global $wpdb;

		$wpdb->prefix = $this->original_prefix;
		foreach ( $this->clients as $client ) {
			remove_action( 'deleted_post', [ $client->getStorage(), 'deleteByObjectID' ] );
		}

		foreach ( array_unique( $this->physical_tables ) as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '``', $table ) . '`' );
		}

		$current_options = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options}" );
		foreach ( array_diff( $current_options, $this->original_option_names ) as $option_name ) {
			delete_option( $option_name );
		}

		foreach ( array_diff( $wpdb->tables, $this->original_tables ) as $table_key ) {
			unset( $wpdb->{$table_key} );
		}
		$wpdb->tables = $this->original_tables;

		remove_all_filters( 'wpConnections/factory/getStorage/class' );
		remove_all_filters( 'wpConnections/storage/installOnInit' );
		parent::tear_down();
	}

	public function test_logical_names_normalize_once_and_invalid_input_fails_before_factory(): void
	{
		$factory_calls  = 0;
		$sanitize_calls = 0;
		$factory_filter = static function ( string $class ) use ( &$factory_calls ): string {
			$factory_calls++;
			return ClientIsolationMemoryStorage::class;
		};
		$sanitize_filter = static function ( string $title ) use ( &$sanitize_calls ): string {
			$sanitize_calls++;
			return $title;
		};
		add_filter( 'wpConnections/factory/getStorage/class', $factory_filter );
		add_filter( 'sanitize_title', $sanitize_filter );

		try {
			$aliases = [
				'MY CLIENT'   => 'my-client',
				'café'        => 'cafe',
				'hello/world' => 'hello-world',
				'a.b'         => 'a-b',
			];
			foreach ( $aliases as $raw => $canonical ) {
				$client = $this->remember_client( new Client( $raw ) );
				self::assertSame( $canonical, $client->getName() );
			}

			self::assertSame( count( $aliases ), $factory_calls );
			self::assertSame( count( $aliases ), $sanitize_calls );

			foreach ( [ 123, [], new \stdClass() ] as $invalid_type ) {
				$this->assert_client_registration_error(
					'Client name must be a string.',
					static function () use ( $invalid_type ): void {
						new Client( $invalid_type );
					}
				);
			}

			foreach ( [ '!!!', 'Клиент', 'a%2Fb' ] as $unsafe ) {
				$this->assert_client_registration_error(
					'Client name is empty or unsafe after normalization.',
					static function () use ( $unsafe ): void {
						new Client( $unsafe );
					}
				);
			}

			self::assertSame( count( $aliases ), $factory_calls );
			self::assertSame( count( $aliases ) + 3, $sanitize_calls );
		} finally {
			remove_filter( 'sanitize_title', $sanitize_filter );
			remove_filter( 'wpConnections/factory/getStorage/class', $factory_filter );
		}
	}

	public function test_default_storage_rejects_collision_and_65_character_identifier(): void
	{
		global $wpdb;

		$first = $this->new_default_client( 'my-client' );
		self::assertSame( 'post_connections_my_client', $first->getStorage()->get_connections_table() );
		self::assertSame( 'post_connections_meta_my_client', $first->getStorage()->get_meta_table() );

		$tables_after_first = $wpdb->tables;
		$this->assert_client_registration_error(
			'Client table mapping is already claimed by another client.',
			function (): void {
				$this->new_default_client( 'my_client' );
			}
		);
		self::assertSame( $tables_after_first, $wpdb->tables );

		$maximum = 64 - strlen( $wpdb->prefix ) - strlen( WPStorage::META_TABLE_PREFIX );
		$boundary = $this->new_default_client( str_repeat( 'a', $maximum ) );
		self::assertSame(
			64,
			strlen( $wpdb->prefix . $boundary->getStorage()->get_meta_table() )
		);

		$tables_at_boundary = $wpdb->tables;
		$this->assert_client_registration_error(
			'Client table identifier exceeds the 64-character database limit.',
			function () use ( $maximum ): void {
				$this->new_default_client( str_repeat( 'b', $maximum + 1 ) );
			}
		);
		self::assertSame( $tables_at_boundary, $wpdb->tables );
	}

	public function test_default_storage_is_prefix_bound_and_fresh_client_uses_new_prefix(): void
	{
		global $wpdb;

		$bound = $this->new_default_client( 'site-bound' );
		$wpdb->prefix = 'alternate_';

		$this->assert_client_registration_error(
			'Client storage is bound to a different WordPress site prefix.',
			static function () use ( $bound ): void {
				$bound->getStorage()->findConnections( new ConnectionQuery( 1, 2 ) );
			}
		);

		$fresh = $this->new_default_client( 'site-fresh' );
		self::assertSame(
			'alternate_post_connections_site_fresh',
			$wpdb->prefix . $fresh->getStorage()->get_connections_table()
		);
	}

	public function test_custom_non_table_storage_skips_physical_rules_but_not_logical_rules(): void
	{
		$storage_filter = static function (): string {
			return ClientIsolationMemoryStorage::class;
		};
		add_filter( 'wpConnections/factory/getStorage/class', $storage_filter );

		try {
			$client = $this->remember_client( new Client( str_repeat( 'c', 100 ) ) );
			self::assertInstanceOf( ClientIsolationMemoryStorage::class, $client->getStorage() );
			self::assertFalse( method_exists( $client->getStorage(), 'get_connections_table' ) );

			$this->assert_client_registration_error(
				'Client name is empty or unsafe after normalization.',
				static function (): void {
					new Client( '!!!' );
				}
			);
		} finally {
			remove_filter( 'wpConnections/factory/getStorage/class', $storage_filter );
		}
	}

	private function new_default_client( string $name ): Client
	{
		$install_filter = '__return_false';
		add_filter( 'wpConnections/storage/installOnInit', $install_filter, 999, 2 );
		try {
			$client = $this->remember_client( new Client( $name ) );
		} finally {
			remove_filter( 'wpConnections/storage/installOnInit', $install_filter, 999 );
		}

		global $wpdb;
		if ( $client->getStorage() instanceof WPStorage ) {
			$this->physical_tables[] = $wpdb->prefix . $client->getStorage()->get_connections_table();
			$this->physical_tables[] = $wpdb->prefix . $client->getStorage()->get_meta_table();
		}

		return $client;
	}

	private function remember_client( Client $client ): Client
	{
		$this->clients[] = $client;
		return $client;
	}

	private function assert_client_registration_error( string $message, callable $operation ): void
	{
		try {
			$operation();
		} catch ( \Throwable $exception ) {
			self::assertSame( ClientRegisterFail::class, get_class( $exception ) );
			self::assertSame( 4, $exception->getCode() );
			self::assertSame( $message, $exception->getMessage() );
			return;
		}

		self::fail( 'Expected ClientRegisterFail.' );
	}
}
