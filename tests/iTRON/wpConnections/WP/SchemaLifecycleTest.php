<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Client;
use iTRON\wpConnections\Exceptions\ConnectionWrongData;
use iTRON\wpConnections\Internal\RestRouteRegistry;
use iTRON\wpConnections\Query\Connection as ConnectionQuery;
use iTRON\wpConnections\Query\Meta as MetaQuery;
use Throwable;

class SchemaLifecycleTest extends WPConnectionsTestCase
{
	public function tear_down()
	{
		$this->client->disablePostDeletionCleanup();
		parent::tear_down();
	}

	public function test_clean_install_has_issue_45_indexes_and_explicit_innodb_tables(): void
	{
		global $wpdb;

		$default_engine = (string) $wpdb->get_var( 'SELECT @@SESSION.default_storage_engine' );
		$clean_client = null;
		try {
			$wpdb->query( 'SET SESSION default_storage_engine = MyISAM' );
			$clean_client = new Client( 'schema-clean-client' );
			$this->assert_storage_schema( $clean_client );

			$connection_id = $this->create_storage_connection( 'clean-install', true, $clean_client );
			$tables = $this->storage_tables( $clean_client );
			self::assertSame( 1, $this->connection_row_count( $connection_id, $tables['connections'] ) );
			self::assertSame( 1, $this->meta_row_count( $connection_id, $tables['meta'] ) );
		} finally {
			$wpdb->query(
				'SET SESSION default_storage_engine = ' . preg_replace( '/[^A-Za-z0-9_]/', '', $default_engine )
			);
			if ( $clean_client instanceof Client ) {
				$this->drop_auxiliary_client( $clean_client );
			}
		}
	}

	public function test_repeated_install_preserves_schema_and_data(): void
	{
		$first_id = $this->create_storage_connection( 'before-repeat' );

		RestRouteRegistry::instance()->deactivateClient( $this->client );
		$this->client->disablePostDeletionCleanup();
		$this->client = new Client( CLIENT_NAME );

		$this->assert_storage_schema();
		self::assertSame( 1, $this->connection_row_count( $first_id ) );
		self::assertSame( 1, $this->meta_row_count( $first_id ) );

		$second_id = $this->create_storage_connection( 'after-repeat' );

		self::assertNotSame( $first_id, $second_id );
		self::assertSame( 1, $this->connection_row_count( $first_id ) );
		self::assertSame( 1, $this->connection_row_count( $second_id ) );
		self::assertSame( 1, $this->meta_row_count( $first_id ) );
		self::assertSame( 1, $this->meta_row_count( $second_id ) );
	}

	/**
	 * @dataProvider missing_table_provider
	 */
	public function test_missing_tables_are_recovered_once_before_create_dml( string $missing ): void
	{
		global $wpdb;

		$tables = $this->storage_tables();
		$preserved_connection_id = null;
		$preserved_meta_connection_id = 987654321;

		if ( 'meta' === $missing ) {
			$preserved_connection_id = $this->create_storage_connection( 'preserved-connection', false );
		}

		if ( 'connections' === $missing ) {
			self::assertSame(
				1,
				$wpdb->insert(
					$tables['meta'],
					[
						'connection_id' => $preserved_meta_connection_id,
						'meta_key'      => 'preserved',
						'meta_value'    => 'sentinel',
					]
				)
			);
		}

		$missing_tables = 'both' === $missing ? array_values( $tables ) : [ $tables[ $missing ] ];
		foreach ( $missing_tables as $table ) {
			$wpdb->query( "DROP TABLE `{$table}`" );
		}

		$queries = [];
		$events = [];
		$query_recorder = static function ( string $query ) use ( &$queries, &$events ): string {
			$queries[] = $query;
			$events[] = $query;
			return $query;
		};
		$attempt_recorder = static function () use ( &$events ): void {
			$events[] = '__connection_insert_attempt__';
		};
		add_filter( 'query', $query_recorder );
		add_action( 'iTRON/wpConnections/storage/createConnection/attempt', $attempt_recorder );

		$created_id = null;
		$failure = null;
		try {
			$created_id = $this->create_storage_connection( 'after-recovery' );
		} catch ( Throwable $exception ) {
			$failure = $exception;
		} finally {
			remove_action( 'iTRON/wpConnections/storage/createConnection/attempt', $attempt_recorder );
			remove_filter( 'query', $query_recorder );
		}

		self::assertNull(
			$failure,
			null === $failure ? '' : get_class( $failure ) . ': ' . $failure->getMessage()
		);
		self::assertIsInt( $created_id );
		self::assertGreaterThan( 0, $created_id );

		$first_insert = $this->first_table_insert_index( $queries, $tables['connections'] );
		self::assertNotNull( $first_insert, 'The requested connection INSERT was not observed.' );
		$first_attempt = array_search( '__connection_insert_attempt__', $events, true );
		self::assertIsInt( $first_attempt, 'The connection INSERT attempt hook was not observed.' );
		foreach ( $missing_tables as $table ) {
			$create_indexes = $this->table_create_indexes( $queries, $table );
			$create_event_indexes = $this->table_create_indexes( $events, $table );
			self::assertCount(
				1,
				$create_indexes,
				"Expected one bounded recovery attempt for {$table}. Observed queries:\n" . implode( "\n", $queries )
			);
			self::assertCount( 1, $create_event_indexes );
			self::assertLessThan(
				$first_attempt,
				$create_event_indexes[0],
				"Recovery for {$table} must happen before the first connection INSERT attempt."
			);
		}

		$this->assert_storage_schema();
		self::assertSame( 1, $this->connection_row_count( $created_id ) );
		self::assertSame( 1, $this->meta_row_count( $created_id ) );

		if ( null !== $preserved_connection_id ) {
			self::assertSame( 1, $this->connection_row_count( $preserved_connection_id ) );
		}

		if ( 'connections' === $missing ) {
			self::assertSame( 1, $this->meta_row_count( $preserved_meta_connection_id ) );
		}
	}

	public function missing_table_provider(): array
	{
		return [
			'connections table only' => [ 'connections' ],
			'meta table only'        => [ 'meta' ],
			'both tables'             => [ 'both' ],
		];
	}

	public function test_failed_recovery_is_bounded_informative_and_performs_no_dml(): void
	{
		global $wpdb;

		$tables = $this->storage_tables();
		foreach ( $tables as $table ) {
			$wpdb->query( "DROP TABLE `{$table}`" );
		}

		$queries = [];
		$block_schema_create = function ( string $query ) use ( &$queries, $tables ): string {
			$queries[] = $query;
			foreach ( $tables as $table ) {
				if ( $this->is_create_table_query( $query, $table ) ) {
					return 'SELECT 1 /* SchemaLifecycleTest blocked CREATE TABLE */';
				}
			}

			return $query;
		};
		add_filter( 'query', $block_schema_create );

		$failure = null;
		try {
			$this->create_storage_connection( 'failed-recovery' );
		} catch ( Throwable $exception ) {
			$failure = $exception;
		} finally {
			remove_filter( 'query', $block_schema_create );
		}

		self::assertInstanceOf( ConnectionWrongData::class, $failure );
		self::assertStringContainsString( 'schema', strtolower( $failure->getMessage() ) );
		self::assertStringContainsString( $tables['connections'], $failure->getMessage() );
		self::assertStringContainsString( $tables['meta'], $failure->getMessage() );
		self::assertStringContainsString( 'InnoDB', $failure->getMessage() );
		self::assertSame( [], $this->table_dml_queries( $queries, $tables ) );

		foreach ( $tables as $table ) {
			self::assertCount( 1, $this->table_create_indexes( $queries, $table ) );
			self::assertFalse( $this->table_exists( $table ) );
		}
	}

	/**
	 * @dataProvider incompatible_engine_provider
	 */
	public function test_incompatible_engines_fail_before_dml_without_implicit_alter( array $incompatible ): void
	{
		global $wpdb;

		$tables = $this->storage_tables();
		foreach ( $incompatible as $table_key ) {
			$wpdb->query( "ALTER TABLE `{$tables[ $table_key ]}` ENGINE=MyISAM" );
			self::assertSame( 'MYISAM', $this->table_engine( $tables[ $table_key ] ) );
		}

		$queries = [];
		$query_recorder = static function ( string $query ) use ( &$queries ): string {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $query_recorder );

		$failure = null;
		try {
			$this->create_storage_connection( 'incompatible-engine' );
		} catch ( Throwable $exception ) {
			$failure = $exception;
		} finally {
			remove_filter( 'query', $query_recorder );
		}

		self::assertInstanceOf( ConnectionWrongData::class, $failure );
		self::assertStringContainsString( 'InnoDB', $failure->getMessage() );
		foreach ( $incompatible as $table_key ) {
			self::assertStringContainsString( $tables[ $table_key ], $failure->getMessage() );
			self::assertSame( 'MYISAM', $this->table_engine( $tables[ $table_key ] ) );
		}
		self::assertSame( [], $this->table_dml_queries( $queries, $tables ) );
		self::assertSame( [], $this->table_alter_queries( $queries, $tables ) );
	}

	public function incompatible_engine_provider(): array
	{
		return [
			'both tables use MyISAM'       => [ [ 'connections', 'meta' ] ],
			'connections table is MyISAM' => [ [ 'connections' ] ],
			'meta table is MyISAM'        => [ [ 'meta' ] ],
		];
	}

	private function create_storage_connection(
		string $marker,
		bool $with_meta = true,
		?Client $client = null
	): int
	{
		$client = $client ?? $this->client;
		$query = new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] );
		$query->set( 'relation', RELATION_0_NAME );
		$query->set( 'title', $marker );
		$query->set( 'order', 0 );
		if ( $with_meta ) {
			$query->meta->add( new MetaQuery( 'schema-marker', $marker ) );
		}

		return $client->getStorage()->createConnection( $query );
	}

	/**
	 * @return array{connections: string, meta: string}
	 */
	private function storage_tables( ?Client $client = null ): array
	{
		global $wpdb;
		$client = $client ?? $this->client;

		return [
			'connections' => $wpdb->prefix . $client->getStorage()->get_connections_table(),
			'meta'        => $wpdb->prefix . $client->getStorage()->get_meta_table(),
		];
	}

	private function assert_storage_schema( ?Client $client = null ): void
	{
		$tables = $this->storage_tables( $client );

		self::assertSame(
			[ 'ID', 'relation', 'from', 'to', 'order', 'title' ],
			$this->table_columns( $tables['connections'] )
		);
		self::assertSame(
			[
				'PRIMARY'  => [ 'ID' ],
				'from'     => [ 'from' ],
				'order'    => [ 'order' ],
				'relation' => [ 'relation' ],
				'to'       => [ 'to' ],
			],
			$this->table_indexes( $tables['connections'] )
		);
		self::assertSame(
			[ 'meta_id', 'connection_id', 'meta_key', 'meta_value' ],
			$this->table_columns( $tables['meta'] )
		);
		self::assertSame(
			[
				'PRIMARY'       => [ 'meta_id' ],
				'connection_id' => [ 'connection_id' ],
				'meta_key'      => [ 'meta_key' ],
			],
			$this->table_indexes( $tables['meta'] )
		);
		self::assertSame( 'INNODB', $this->table_engine( $tables['connections'] ) );
		self::assertSame( 'INNODB', $this->table_engine( $tables['meta'] ) );
	}

	private function table_exists( string $table ): bool
	{
		global $wpdb;

		return 1 === (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
				$table
			)
		);
	}

	private function table_engine( string $table ): string
	{
		global $wpdb;

		return strtoupper(
			(string) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT ENGINE FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
					$table
				)
			)
		);
	}

	private function table_columns( string $table ): array
	{
		global $wpdb;

		return $wpdb->get_col( 'SHOW COLUMNS FROM `' . str_replace( '`', '``', $table ) . '`' );
	}

	private function table_indexes( string $table ): array
	{
		global $wpdb;

		$indexes = [];
		$rows = $wpdb->get_results(
			'SHOW INDEX FROM `' . str_replace( '`', '``', $table ) . '`',
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			$indexes[ $row['Key_name'] ][ (int) $row['Seq_in_index'] ] = $row['Column_name'];
		}

		foreach ( $indexes as &$columns ) {
			ksort( $columns, SORT_NUMERIC );
			$columns = array_values( $columns );
		}
		unset( $columns );
		ksort( $indexes, SORT_STRING );

		return $indexes;
	}

	private function connection_row_count( int $connection_id, ?string $table = null ): int
	{
		global $wpdb;

		$table = $table ?? $this->storage_tables()['connections'];
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE `ID` = %d", $connection_id )
		);
	}

	private function meta_row_count( int $connection_id, ?string $table = null ): int
	{
		global $wpdb;

		$table = $table ?? $this->storage_tables()['meta'];
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE `connection_id` = %d", $connection_id )
		);
	}

	private function first_table_insert_index( array $queries, string $table ): ?int
	{
		foreach ( $queries as $index => $query ) {
			if (
				1 === preg_match( '/^\s*INSERT\s+INTO\s+`?' . preg_quote( $table, '/' ) . '`?/i', $query )
			) {
				return $index;
			}
		}

		return null;
	}

	private function table_create_indexes( array $queries, string $table ): array
	{
		$indexes = [];
		foreach ( $queries as $index => $query ) {
			if ( $this->is_create_table_query( $query, $table ) ) {
				$indexes[] = $index;
			}
		}

		return $indexes;
	}

	private function is_create_table_query( string $query, string $table ): bool
	{
		return 1 === preg_match( '/CREATE\s+(?:TEMPORARY\s+)?TABLE\b/i', $query ) &&
			false !== strpos( $query, $table );
	}

	private function table_dml_queries( array $queries, array $tables ): array
	{
		return array_values(
			array_filter(
				$queries,
				static function ( string $query ) use ( $tables ): bool {
					if ( 1 !== preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE)\b/i', $query ) ) {
						return false;
					}

					return false !== strpos( $query, $tables['connections'] ) ||
						false !== strpos( $query, $tables['meta'] );
				}
			)
		);
	}

	private function table_alter_queries( array $queries, array $tables ): array
	{
		return array_values(
			array_filter(
				$queries,
				static function ( string $query ) use ( $tables ): bool {
					if ( 1 !== preg_match( '/^\s*ALTER\s+TABLE\b/i', $query ) ) {
						return false;
					}

					return false !== strpos( $query, $tables['connections'] ) ||
						false !== strpos( $query, $tables['meta'] );
				}
			)
		);
	}

	private function drop_auxiliary_client( Client $client ): void
	{
		global $wpdb;

		RestRouteRegistry::instance()->deactivateClient( $client );
		$client->disablePostDeletionCleanup();
		$storage = $client->getStorage();
		$table_keys = [ $storage->get_connections_table(), $storage->get_meta_table() ];
		foreach ( $table_keys as $table_key ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$table_key}`" );
			unset( $wpdb->{$table_key} );
		}
		$wpdb->tables = array_values( array_diff( $wpdb->tables, $table_keys ) );

		$postfix = str_replace( '-', '_', $client->getName() );
		delete_option( 'wpconnections_storage_owner_' . hash( 'sha256', $postfix ) );
	}
}
