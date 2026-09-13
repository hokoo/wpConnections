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
		self::assertSame( [], $this->table_alter_queries( $queries, $tables ) );

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

	public function test_second_table_ddl_failure_preserves_owned_empty_partial_schema_and_next_client_recovers_it(): void
	{
		global $wpdb;

		$tables = $this->storage_tables();
		foreach ( $tables as $table ) {
			$wpdb->query( "DROP TABLE `{$table}`" );
		}

		$failed_queries = [];
		$block_meta_create = function ( string $query ) use ( &$failed_queries, $tables ): string {
			$failed_queries[] = $query;
			if ( $this->is_create_table_query( $query, $tables['meta'] ) ) {
				return 'SELECT 1 /* SchemaLifecycleTest blocked second CREATE TABLE */';
			}

			return $query;
		};
		add_filter( 'query', $block_meta_create );

		$failure = null;
		try {
			$this->create_storage_connection( 'partial-schema-failure' );
		} catch ( Throwable $exception ) {
			$failure = $exception;
		} finally {
			remove_filter( 'query', $block_meta_create );
		}

		self::assertInstanceOf( ConnectionWrongData::class, $failure );
		self::assertStringContainsString( 'schema', strtolower( $failure->getMessage() ) );
		self::assertStringContainsString( $tables['meta'], $failure->getMessage() );
		self::assertStringContainsString( 'missing', strtolower( $failure->getMessage() ) );
		self::assertCount( 1, $this->table_create_indexes( $failed_queries, $tables['connections'] ) );
		self::assertCount( 1, $this->table_create_indexes( $failed_queries, $tables['meta'] ) );
		self::assertTrue( $this->table_exists( $tables['connections'] ) );
		self::assertFalse( $this->table_exists( $tables['meta'] ) );
		self::assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$tables['connections']}`" ) );
		self::assertSame( [], $this->table_dml_queries( $failed_queries, $tables ) );
		self::assertSame( [], $this->table_alter_queries( $failed_queries, $tables ) );
		self::assertSame( [], $this->table_drop_queries( $failed_queries, $tables ) );

		$ownership = get_option( $this->ownership_option_name(), null );
		self::assertIsArray( $ownership );
		self::assertSame( 1, $ownership['version'] ?? null );
		self::assertSame( str_replace( '-', '_', $this->client->getName() ), $ownership['postfix'] ?? null );
		self::assertSame( $this->client->getName(), $ownership['owner'] ?? null );

		$old_client = $this->client;
		RestRouteRegistry::instance()->deactivateClient( $old_client );
		$old_client->disablePostDeletionCleanup();

		$recovery_queries = [];
		$record_recovery = static function ( string $query ) use ( &$recovery_queries ): string {
			$recovery_queries[] = $query;
			return $query;
		};
		add_filter( 'query', $record_recovery );
		try {
			$this->client = new Client( CLIENT_NAME );
			$created_id = $this->create_storage_connection( 'after-partial-schema-recovery' );
		} finally {
			remove_filter( 'query', $record_recovery );
		}

		self::assertCount( 0, $this->table_create_indexes( $recovery_queries, $tables['connections'] ) );
		$meta_create_indexes = $this->table_create_indexes( $recovery_queries, $tables['meta'] );
		self::assertCount( 1, $meta_create_indexes );
		$first_insert = $this->first_table_insert_index( $recovery_queries, $tables['connections'] );
		self::assertIsInt( $first_insert, 'The recovered operation did not reach its connection INSERT.' );
		self::assertLessThan( $first_insert, $meta_create_indexes[0] );
		$this->assert_storage_schema();
		self::assertSame( 1, $this->connection_row_count( $created_id ) );
		self::assertSame( 1, $this->meta_row_count( $created_id ) );
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

	/**
	 * @dataProvider incompatible_schema_provider
	 */
	public function test_incompatible_schema_fails_before_dml_without_implicit_alter(
		string $table_key,
		string $alter,
		string $expected_issue
	): void {
		global $wpdb;

		$tables = $this->storage_tables();
		$wpdb->query( sprintf( $alter, $tables[ $table_key ] ) );
		self::assertSame( '', $wpdb->last_error );

		$queries = [];
		$query_recorder = static function ( string $query ) use ( &$queries ): string {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $query_recorder );

		$failure = null;
		try {
			$this->create_storage_connection( 'incompatible-schema' );
		} catch ( Throwable $exception ) {
			$failure = $exception;
		} finally {
			remove_filter( 'query', $query_recorder );
		}

		self::assertInstanceOf( ConnectionWrongData::class, $failure );
		self::assertStringContainsString( $tables[ $table_key ], $failure->getMessage() );
		self::assertStringContainsString( $expected_issue, $failure->getMessage() );
		self::assertSame( [], $this->table_dml_queries( $queries, $tables ) );
		self::assertSame( [], $this->table_alter_queries( $queries, $tables ) );
	}

	public function incompatible_schema_provider(): array
	{
		return [
			'wrong varchar length' => [
				'connections',
				'ALTER TABLE `%s` MODIFY `relation` varchar(1) NOT NULL',
				'incompatible columns',
			],
			'missing unsigned attribute' => [
				'connections',
				'ALTER TABLE `%s` MODIFY `from` bigint(20) NOT NULL',
				'incompatible columns',
			],
			'wrong nullability' => [
				'connections',
				'ALTER TABLE `%s` MODIFY `relation` varchar(255) NULL',
				'incompatible columns',
			],
			'wrong default' => [
				'connections',
				"ALTER TABLE `%s` MODIFY `order` bigint(20) unsigned NULL default '7'",
				'incompatible columns',
			],
			'missing auto increment' => [
				'connections',
				'ALTER TABLE `%s` MODIFY `ID` bigint(20) unsigned NOT NULL',
				'incompatible columns',
			],
			'wrong index uniqueness' => [
				'connections',
				'ALTER TABLE `%s` DROP INDEX `from`, ADD UNIQUE KEY `from` (`from`)',
				'missing or incompatible indexes',
			],
			'prefix index instead of full column' => [
				'connections',
				'ALTER TABLE `%s` DROP INDEX `relation`, ADD KEY `relation` (`relation`(16))',
				'missing or incompatible indexes',
			],
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
			[
				'ID'       => [ 'type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'extra' => 'auto_increment' ],
				'relation' => [ 'type' => 'varchar(255)', 'nullable' => false, 'default' => null, 'extra' => '' ],
				'from'     => [ 'type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'extra' => '' ],
				'to'       => [ 'type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'extra' => '' ],
				'order'    => [ 'type' => 'bigint unsigned', 'nullable' => true, 'default' => '0', 'extra' => '' ],
				'title'    => [ 'type' => 'varchar(63)', 'nullable' => true, 'default' => '', 'extra' => '' ],
			],
			$this->table_columns( $tables['connections'] )
		);
		self::assertSame(
			[
				'PRIMARY'  => [ 'non_unique' => 0, 'type' => 'BTREE', 'usable' => true, 'columns' => [ [ 'name' => 'ID', 'prefix' => null ] ] ],
				'from'     => [ 'non_unique' => 1, 'type' => 'BTREE', 'usable' => true, 'columns' => [ [ 'name' => 'from', 'prefix' => null ] ] ],
				'order'    => [ 'non_unique' => 1, 'type' => 'BTREE', 'usable' => true, 'columns' => [ [ 'name' => 'order', 'prefix' => null ] ] ],
				'relation' => [ 'non_unique' => 1, 'type' => 'BTREE', 'usable' => true, 'columns' => [ [ 'name' => 'relation', 'prefix' => null ] ] ],
				'to'       => [ 'non_unique' => 1, 'type' => 'BTREE', 'usable' => true, 'columns' => [ [ 'name' => 'to', 'prefix' => null ] ] ],
			],
			$this->table_indexes( $tables['connections'] )
		);
		self::assertSame(
			[
				'meta_id'       => [ 'type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'extra' => 'auto_increment' ],
				'connection_id' => [ 'type' => 'bigint unsigned', 'nullable' => false, 'default' => '0', 'extra' => '' ],
				'meta_key'      => [ 'type' => 'varchar(255)', 'nullable' => false, 'default' => null, 'extra' => '' ],
				'meta_value'    => [ 'type' => 'longtext', 'nullable' => false, 'default' => null, 'extra' => '' ],
			],
			$this->table_columns( $tables['meta'] )
		);
		self::assertSame(
			[
				'PRIMARY'       => [ 'non_unique' => 0, 'type' => 'BTREE', 'usable' => true, 'columns' => [ [ 'name' => 'meta_id', 'prefix' => null ] ] ],
				'connection_id' => [ 'non_unique' => 1, 'type' => 'BTREE', 'usable' => true, 'columns' => [ [ 'name' => 'connection_id', 'prefix' => null ] ] ],
				'meta_key'      => [ 'non_unique' => 1, 'type' => 'BTREE', 'usable' => true, 'columns' => [ [ 'name' => 'meta_key', 'prefix' => null ] ] ],
			],
			$this->table_indexes( $tables['meta'] )
		);
		self::assertSame( 'INNODB', $this->table_engine( $tables['connections'] ) );
		self::assertSame( 'INNODB', $this->table_engine( $tables['meta'] ) );
	}

	private function table_exists( string $table ): bool
	{
		global $wpdb;

		$escaped_table = str_replace( '`', '``', $table );
		$suppress       = $wpdb->suppress_errors();
		try {
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$escaped_table}`" );
		} finally {
			$wpdb->suppress_errors( $suppress );
		}

		return is_array( $columns ) && [] !== $columns;
	}

	private function ownership_option_name(): string
	{
		$postfix = str_replace( '-', '_', $this->client->getName() );

		return 'wpconnections_storage_owner_' . hash( 'sha256', $postfix );
	}

	private function table_engine( string $table ): string
	{
		global $wpdb;

		$escaped_table = str_replace( '`', '``', $table );
		$definition    = $wpdb->get_row( "SHOW CREATE TABLE `{$escaped_table}`", ARRAY_N );
		if ( ! is_array( $definition ) || ! isset( $definition[1] ) ) {
			return '';
		}

		return preg_match( '/\bENGINE=([A-Za-z0-9_]+)/i', $definition[1], $matches )
			? strtoupper( $matches[1] )
			: '';
	}

	private function table_columns( string $table ): array
	{
		global $wpdb;

		$columns = [];
		$rows    = $wpdb->get_results(
			'SHOW FULL COLUMNS FROM `' . str_replace( '`', '``', $table ) . '`',
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			$columns[ $row['Field'] ] = [
				'type'     => $this->normalize_column_type( (string) $row['Type'] ),
				'nullable' => 'YES' === $row['Null'],
				'default'  => $row['Default'],
				'extra'    => $this->normalize_column_extra( (string) $row['Extra'] ),
			];
		}

		return $columns;
	}

	private function normalize_column_type( string $type ): string
	{
		$type = strtolower( trim( preg_replace( '/\s+/', ' ', $type ) ) );

		return preg_replace(
			'/\b(tinyint|smallint|mediumint|int|integer|bigint)\([0-9]+\)/',
			'$1',
			$type
		);
	}

	private function normalize_column_extra( string $extra ): string
	{
		$extra = strtolower( trim( $extra ) );

		return 'null' === $extra ? '' : $extra;
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
			$name = $row['Key_name'];
			if ( ! isset( $indexes[ $name ] ) ) {
				$indexes[ $name ] = [
					'non_unique' => (int) $row['Non_unique'],
					'type'       => strtoupper( (string) $row['Index_type'] ),
					'usable'     => ( ! isset( $row['Visible'] ) || 'YES' === strtoupper( (string) $row['Visible'] ) ) &&
						( ! isset( $row['Ignored'] ) || 'NO' === strtoupper( (string) $row['Ignored'] ) ),
					'columns'    => [],
				];
			}
			$indexes[ $name ]['columns'][ (int) $row['Seq_in_index'] ] = [
				'name'   => $row['Column_name'],
				'prefix' => null === $row['Sub_part'] ? null : (int) $row['Sub_part'],
			];
		}

		foreach ( $indexes as &$index ) {
			ksort( $index['columns'], SORT_NUMERIC );
			$index['columns'] = array_values( $index['columns'] );
		}
		unset( $index );
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
		return 1 === preg_match( '/^\s*CREATE\s+(?:TEMPORARY\s+)?TABLE\b/i', $query ) &&
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

	private function table_drop_queries( array $queries, array $tables ): array
	{
		return array_values(
			array_filter(
				$queries,
				static function ( string $query ) use ( $tables ): bool {
					if ( 1 !== preg_match( '/^\s*DROP\s+TABLE\b/i', $query ) ) {
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
