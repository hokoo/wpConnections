<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use Error;
use iTRON\wpConnections\Internal\DeletedPostRepairLedger;
use Throwable;

class DeletedPostRepairSchemaTest extends \WP_UnitTestCase
{
	private const TABLE_KEY        = 'wpconnections_repair';
	private const OWNERSHIP_OPTION = 'wpconnections_repair_schema_owner';
	private const OWNER            = 'hokoo/wpconnections';
	private const SCHEMA_VERSION   = 1;

	private array $wpdb_tables_before_test = [];
	private string $table;

	public function set_up()
	{
		parent::set_up();

		global $wpdb;
		$this->wpdb_tables_before_test = $wpdb->tables;
		$this->table                   = $wpdb->prefix . self::TABLE_KEY;
		add_filter( 'query', [ $this, 'preserve_real_repair_ledger_table' ], 11 );
		$this->reset_ledger_artifacts();
	}

	public function tear_down()
	{
		try {
			$this->reset_ledger_artifacts();
		} finally {
			remove_filter( 'query', [ $this, 'preserve_real_repair_ledger_table' ], 11 );
			parent::tear_down();
		}
	}

	public function test_clean_install_uses_the_exact_owned_nonautoloaded_innodb_schema(): void
	{
		global $wpdb;

		$default_engine = (string) $wpdb->get_var( 'SELECT @@SESSION.default_storage_engine' );
		try {
			$wpdb->query( 'SET SESSION default_storage_engine = MyISAM' );

			$ledger = new DeletedPostRepairLedger();
			self::assertSame( $this->table, $ledger->getTableName() );
			$ledger->ensureReady();
			$ledger->assertReady();

			self::assertTrue( $this->table_exists( $this->table ) );
			self::assertSame( 'INNODB', $this->table_engine( $this->table ) );
			self::assertSame( $this->expected_columns(), $this->table_columns( $this->table ) );
			self::assertSame( $this->expected_indexes(), $this->table_indexes( $this->table ) );
			self::assertSame( $this->expected_ownership(), get_option( self::OWNERSHIP_OPTION ) );
			self::assertFalse( $this->ownership_option_is_autoloaded() );
			self::assertSame( $this->table, $wpdb->{self::TABLE_KEY} );
			self::assertSame( 1, count( array_keys( $wpdb->tables, self::TABLE_KEY, true ) ) );
		} finally {
			$wpdb->query(
				'SET SESSION default_storage_engine = ' . preg_replace( '/[^A-Za-z0-9_]/', '', $default_engine )
			);
		}
	}

	public function test_exact_names_do_not_collide_with_the_valid_repair_client_tables_or_unrelated_options(): void
	{
		global $wpdb;

		$client_collision_table = $wpdb->prefix . 'post_connections_repair';
		$unrelated_option       = self::OWNERSHIP_OPTION . '_user_sentinel';
		if ( $this->table_exists( $client_collision_table ) || false !== get_option( $unrelated_option, false ) ) {
			$this->markTestSkipped( 'The collision sentinel is already user-owned; the test will not modify it.' );
		}

		$created_table  = false;
		$created_option = false;
		try {
			$created_table = false !== $wpdb->query(
				"CREATE TABLE `{$client_collision_table}` (`sentinel` bigint unsigned NOT NULL) ENGINE=InnoDB"
			);
			self::assertTrue( $created_table );
			self::assertSame( 1, $wpdb->insert( $client_collision_table, [ 'sentinel' => 734 ] ) );
			$created_option = add_option( $unrelated_option, 'preserve-me', '', false );
			self::assertTrue( $created_option );

			$ledger = new DeletedPostRepairLedger();
			self::assertSame( $wpdb->prefix . 'wpconnections_repair', $ledger->getTableName() );
			self::assertNotSame( $client_collision_table, $ledger->getTableName() );
			$ledger->ensureReady();

			$this->reset_ledger_artifacts();

			self::assertTrue( $this->table_exists( $client_collision_table ) );
			self::assertSame( '734', $wpdb->get_var( "SELECT `sentinel` FROM `{$client_collision_table}`" ) );
			self::assertSame( 'preserve-me', get_option( $unrelated_option ) );
		} finally {
			if ( $created_table ) {
				$wpdb->query( "DROP TABLE `{$client_collision_table}`" );
			}
			if ( $created_option ) {
				delete_option( $unrelated_option );
			}
		}
	}

	public function test_repeated_readiness_checks_preserve_the_schema_and_issue_no_ddl(): void
	{
		$ledger = new DeletedPostRepairLedger();
		$ledger->ensureReady();
		$ownership = get_option( self::OWNERSHIP_OPTION );

		$queries = $this->record_queries(
			static function () use ( $ledger ): void {
				$ledger->ensureReady();
				$ledger->assertReady();
			}
		);

		self::assertSame( [], $this->ddl_queries( $queries ) );
		self::assertSame( $ownership, get_option( self::OWNERSHIP_OPTION ) );
		self::assertSame( $this->expected_columns(), $this->table_columns( $this->table ) );
	}

	public function test_owned_missing_table_is_recovered_once_without_alter_or_drop(): void
	{
		global $wpdb;

		( new DeletedPostRepairLedger() )->ensureReady();
		$ownership = get_option( self::OWNERSHIP_OPTION );
		$wpdb->query( "DROP TABLE `{$this->table}`" );

		$queries = $this->record_queries(
			static function (): void {
				( new DeletedPostRepairLedger() )->ensureReady();
			}
		);

		self::assertCount( 1, $this->create_table_queries( $queries, $this->table ) );
		self::assertSame( [], $this->alter_or_drop_queries( $queries ) );
		self::assertSame( $ownership, get_option( self::OWNERSHIP_OPTION ) );
		self::assertSame( $this->expected_columns(), $this->table_columns( $this->table ) );
		self::assertSame( $this->expected_indexes(), $this->table_indexes( $this->table ) );
		self::assertSame( 'INNODB', $this->table_engine( $this->table ) );
	}

	public function test_assert_ready_validates_only_and_never_installs_or_claims_ownership(): void
	{
		$ledger  = new DeletedPostRepairLedger();
		$failure = null;
		$queries = $this->record_queries(
			static function () use ( $ledger, &$failure ): void {
				try {
					$ledger->assertReady();
				} catch ( Throwable $exception ) {
					$failure = $exception;
				}
			}
		);

		$this->assert_failure( $failure, 'schema' );
		self::assertSame( [], $this->ddl_queries( $queries ) );
		self::assertFalse( $this->table_exists( $this->table ) );
		self::assertFalse( get_option( self::OWNERSHIP_OPTION, false ) );
	}

	public function test_unowned_existing_table_fails_closed_without_claim_alter_or_drop(): void
	{
		( new DeletedPostRepairLedger() )->ensureReady();
		delete_option( self::OWNERSHIP_OPTION );

		$failure = null;
		$queries = $this->record_queries(
			static function () use ( &$failure ): void {
				try {
					( new DeletedPostRepairLedger() )->ensureReady();
				} catch ( Throwable $exception ) {
					$failure = $exception;
				}
			}
		);

		$this->assert_failure( $failure, 'ownership' );
		self::assertSame( [], $this->ddl_queries( $queries ) );
		self::assertFalse( get_option( self::OWNERSHIP_OPTION, false ) );
		self::assertSame( $this->expected_columns(), $this->table_columns( $this->table ) );
	}

	/**
	 * @dataProvider invalid_ownership_provider
	 */
	public function test_malformed_or_incompatible_ownership_fails_closed_without_ddl( $invalid_ownership ): void
	{
		( new DeletedPostRepairLedger() )->ensureReady();
		update_option( self::OWNERSHIP_OPTION, $invalid_ownership, false );

		$failure = null;
		$queries = $this->record_queries(
			static function () use ( &$failure ): void {
				try {
					( new DeletedPostRepairLedger() )->ensureReady();
				} catch ( Throwable $exception ) {
					$failure = $exception;
				}
			}
		);

		$this->assert_failure( $failure, 'ownership' );
		self::assertSame( [], $this->ddl_queries( $queries ) );
		self::assertSame( $invalid_ownership, get_option( self::OWNERSHIP_OPTION ) );
	}

	public function invalid_ownership_provider(): array
	{
		return [
			'malformed scalar' => [ 'claimed-by-someone' ],
			'wrong version'    => [ [ 'version' => 2, 'table' => self::TABLE_KEY, 'owner' => self::OWNER ] ],
			'wrong table'      => [ [ 'version' => 1, 'table' => 'some_other_table', 'owner' => self::OWNER ] ],
			'wrong owner'      => [ [ 'version' => 1, 'table' => self::TABLE_KEY, 'owner' => 'someone/else' ] ],
			'missing owner'    => [ [ 'version' => 1, 'table' => self::TABLE_KEY ] ],
		];
	}

	public function test_wrong_engine_fails_closed_without_implicit_repair(): void
	{
		global $wpdb;

		( new DeletedPostRepairLedger() )->ensureReady();
		$wpdb->query( "ALTER TABLE `{$this->table}` ENGINE=MyISAM" );

		$failure = null;
		$queries = $this->record_queries(
			static function () use ( &$failure ): void {
				try {
					( new DeletedPostRepairLedger() )->ensureReady();
				} catch ( Throwable $exception ) {
					$failure = $exception;
				}
			}
		);

		$this->assert_failure( $failure, 'InnoDB' );
		self::assertSame( [], $this->ddl_queries( $queries ) );
		self::assertSame( 'MYISAM', $this->table_engine( $this->table ) );
	}

	public function test_incompatible_columns_fail_closed_without_implicit_repair(): void
	{
		global $wpdb;

		( new DeletedPostRepairLedger() )->ensureReady();
		$wpdb->query( "ALTER TABLE `{$this->table}` DROP COLUMN `storage_fingerprint`" );

		$failure = null;
		$queries = $this->record_queries(
			static function () use ( &$failure ): void {
				try {
					( new DeletedPostRepairLedger() )->ensureReady();
				} catch ( Throwable $exception ) {
					$failure = $exception;
				}
			}
		);

		$this->assert_failure( $failure, 'schema' );
		self::assertSame( [], $this->ddl_queries( $queries ) );
		self::assertArrayNotHasKey( 'storage_fingerprint', $this->table_columns( $this->table ) );
	}

	public function test_schema_introspection_error_is_not_treated_as_a_missing_table(): void
	{
		global $wpdb;

		( new DeletedPostRepairLedger() )->ensureReady();
		$failure = null;
		$intercepted = false;
		$break_introspection = function ( string $query ) use ( &$intercepted ): string {
			if (
				! $intercepted
				&& 1 === preg_match( '/^\s*SELECT\b/i', $query )
				&& false !== stripos( $query, 'information_schema' )
				&& false !== strpos( $query, $this->table )
			) {
				$intercepted = true;
				return 'SELECT `missing_repair_schema_probe_column` FROM `missing_repair_schema_probe_table`';
			}

			return $query;
		};
		$queries = $this->record_queries(
			function () use ( &$failure, $break_introspection ): void {
				add_filter( 'query', $break_introspection, 9 );
				try {
					( new DeletedPostRepairLedger() )->ensureReady();
				} catch ( Throwable $exception ) {
					$failure = $exception;
				} finally {
					remove_filter( 'query', $break_introspection, 9 );
				}
			}
		);

		self::assertTrue( $intercepted, 'The schema-introspection fault was not injected.' );
		$this->assert_failure( $failure, 'schema' );
		self::assertSame( [], $this->ddl_queries( $queries ) );
		self::assertTrue( $this->table_exists( $this->table ) );
		self::assertSame( $this->expected_ownership(), get_option( self::OWNERSHIP_OPTION ) );
	}

	/**
	 * @dataProvider incompatible_index_provider
	 */
	public function test_incompatible_indexes_fail_closed_without_implicit_repair( string $mutation ): void
	{
		global $wpdb;

		( new DeletedPostRepairLedger() )->ensureReady();
		if ( 'missing' === $mutation ) {
			self::assertNotFalse( $wpdb->query( "ALTER TABLE `{$this->table}` DROP INDEX `status_due`" ) );
		} else {
			self::assertNotFalse(
				$wpdb->query(
					"ALTER TABLE `{$this->table}` ADD KEY `unexpected_repair_index` (`post_id`)"
				)
			);
		}

		$failure = null;
		$queries = $this->record_queries(
			static function () use ( &$failure ): void {
				try {
					( new DeletedPostRepairLedger() )->ensureReady();
				} catch ( Throwable $exception ) {
					$failure = $exception;
				}
			}
		);

		$this->assert_failure( $failure, 'schema' );
		self::assertSame( [], $this->ddl_queries( $queries ) );
		if ( 'missing' === $mutation ) {
			self::assertArrayNotHasKey( 'status_due', $this->table_indexes( $this->table ) );
		} else {
			self::assertArrayHasKey( 'unexpected_repair_index', $this->table_indexes( $this->table ) );
		}
	}

	public function incompatible_index_provider(): array
	{
		return [
			'missing required index' => [ 'missing' ],
			'extra index'            => [ 'extra' ],
		];
	}

	/**
	 * @dataProvider stale_context_provider
	 */
	public function test_captured_site_context_fails_closed_before_ledger_access( string $changed_context ): void
	{
		global $wpdb, $blog_id;

		$ledger           = new DeletedPostRepairLedger();
		$original_prefix  = $wpdb->prefix;
		$original_blog_id = $blog_id;
		$failure          = null;
		try {
			if ( 'prefix' === $changed_context ) {
				$wpdb->prefix = 'other_' . $original_prefix;
			} else {
				$blog_id = (int) $original_blog_id + 1;
			}

			$queries = $this->record_queries(
				static function () use ( $ledger, &$failure ): void {
					try {
						$ledger->ensureReady();
					} catch ( Throwable $exception ) {
						$failure = $exception;
					}
				}
			);
		} finally {
			$wpdb->prefix = $original_prefix;
			$blog_id      = $original_blog_id;
		}

		$this->assert_failure( $failure, 'context' );
		self::assertSame( [], $this->ledger_access_queries( $queries ) );
		self::assertFalse( $this->table_exists( $this->table ) );
		self::assertFalse( get_option( self::OWNERSHIP_OPTION, false ) );
	}

	public function test_replaced_global_wpdb_fails_before_ledger_or_ownership_access(): void
	{
		global $wpdb;

		$original_wpdb   = $wpdb;
		$replacement     = clone $wpdb;
		$ledger          = new DeletedPostRepairLedger();
		$failure         = null;
		$queries         = [];
		$ownership_reads = 0;
		$record_ownership_read = static function ( $value ) use ( &$ownership_reads ) {
			$ownership_reads++;
			return $value;
		};
		add_filter( 'pre_option_' . self::OWNERSHIP_OPTION, $record_ownership_read );
		try {
			$wpdb    = $replacement;
			$queries = $this->record_queries(
				static function () use ( $ledger, &$failure ): void {
					try {
						$ledger->ensureReady();
					} catch ( Throwable $exception ) {
						$failure = $exception;
					}
				}
			);
		} finally {
			$wpdb = $original_wpdb;
			remove_filter( 'pre_option_' . self::OWNERSHIP_OPTION, $record_ownership_read );
		}

		$this->assert_failure( $failure, 'context' );
		self::assertSame( 0, $ownership_reads );
		self::assertSame( [], $this->ledger_access_queries( $queries ) );
		self::assertFalse( $this->table_exists( $this->table ) );
		self::assertFalse( get_option( self::OWNERSHIP_OPTION, false ) );
	}

	public function stale_context_provider(): array
	{
		return [
			'changed table prefix' => [ 'prefix' ],
			'changed site ID'      => [ 'site_id' ],
		];
	}

	public function test_overlong_table_identifier_fails_before_ownership_or_ddl(): void
	{
		global $wpdb;

		$original_prefix = $wpdb->prefix;
		$failure         = null;
		try {
			$wpdb->prefix = str_repeat( 'p', 65 - strlen( self::TABLE_KEY ) );
			$ledger       = new DeletedPostRepairLedger();
			$queries      = $this->record_queries(
				static function () use ( $ledger, &$failure ): void {
					try {
						$ledger->ensureReady();
					} catch ( Throwable $exception ) {
						$failure = $exception;
					}
				}
			);
		} finally {
			$wpdb->prefix = $original_prefix;
		}

		$this->assert_failure( $failure, '64' );
		self::assertSame( [], $this->ddl_queries( $queries ) );
		self::assertFalse( get_option( self::OWNERSHIP_OPTION, false ) );
	}

	public function test_unsafe_table_prefix_fails_before_ownership_or_ddl(): void
	{
		global $wpdb;

		$original_prefix = $wpdb->prefix;
		$unsafe_table = 'unsafe-prefix_' . self::TABLE_KEY;
		$failure = null;
		try {
			$wpdb->prefix = 'unsafe-prefix_';
			$ledger = new DeletedPostRepairLedger();
			$queries = $this->record_queries(
				static function () use ( $ledger, &$failure ): void {
					try {
						$ledger->ensureReady();
					} catch ( Throwable $exception ) {
						$failure = $exception;
					}
				}
			);
		} finally {
			$wpdb->query( "DROP TABLE IF EXISTS `{$unsafe_table}`" );
			delete_option( self::OWNERSHIP_OPTION );
			unset( $wpdb->{self::TABLE_KEY} );
			$wpdb->tables = $this->wpdb_tables_before_test;
			$wpdb->prefix = $original_prefix;
		}

		$this->assert_failure( $failure, 'identifier' );
		self::assertSame( [], $this->ledger_access_queries( $queries ) );
	}

	private function expected_ownership(): array
	{
		return [
			'version' => self::SCHEMA_VERSION,
			'table'   => self::TABLE_KEY,
			'owner'   => self::OWNER,
		];
	}

	private function expected_columns(): array
	{
		return [
			'repair_key'             => [ 'type' => 'char(64)', 'nullable' => false, 'default' => null, 'extra' => '' ],
			'site_id'                => [ 'type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'extra' => '' ],
			'site_prefix'            => [ 'type' => 'varchar(64)', 'nullable' => false, 'default' => null, 'extra' => '' ],
			'client_name'            => [ 'type' => 'varchar(191)', 'nullable' => false, 'default' => null, 'extra' => '' ],
			'storage_class'          => [ 'type' => 'varchar(255)', 'nullable' => false, 'default' => null, 'extra' => '' ],
			'storage_fingerprint'    => [ 'type' => 'char(64)', 'nullable' => false, 'default' => null, 'extra' => '' ],
			'operation'              => [ 'type' => 'varchar(64)', 'nullable' => false, 'default' => null, 'extra' => '' ],
			'post_id'                => [ 'type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'extra' => '' ],
			'status'                 => [ 'type' => 'varchar(32)', 'nullable' => false, 'default' => null, 'extra' => '' ],
			'attempt_count'          => [ 'type' => 'int unsigned', 'nullable' => false, 'default' => '0', 'extra' => '' ],
			'failure_count'          => [ 'type' => 'int unsigned', 'nullable' => false, 'default' => '0', 'extra' => '' ],
			'next_attempt_at'        => [ 'type' => 'datetime', 'nullable' => true, 'default' => null, 'extra' => '' ],
			'lease_token'            => [ 'type' => 'char(64)', 'nullable' => true, 'default' => null, 'extra' => '' ],
			'lease_expires_at'       => [ 'type' => 'datetime', 'nullable' => true, 'default' => null, 'extra' => '' ],
			'failure_category'       => [ 'type' => 'varchar(64)', 'nullable' => true, 'default' => null, 'extra' => '' ],
			'failure_class'          => [ 'type' => 'varchar(255)', 'nullable' => true, 'default' => null, 'extra' => '' ],
			'failure_code'           => [ 'type' => 'varchar(64)', 'nullable' => true, 'default' => null, 'extra' => '' ],
			'failure_summary'        => [ 'type' => 'longtext', 'nullable' => true, 'default' => null, 'extra' => '' ],
			'first_failure_at'       => [ 'type' => 'datetime', 'nullable' => true, 'default' => null, 'extra' => '' ],
			'last_failure_at'        => [ 'type' => 'datetime', 'nullable' => true, 'default' => null, 'extra' => '' ],
			'wakeup_failure_category' => [ 'type' => 'varchar(64)', 'nullable' => true, 'default' => null, 'extra' => '' ],
			'wakeup_failure_summary'  => [ 'type' => 'longtext', 'nullable' => true, 'default' => null, 'extra' => '' ],
			'wakeup_failure_at'        => [ 'type' => 'datetime', 'nullable' => true, 'default' => null, 'extra' => '' ],
			'created_at'               => [ 'type' => 'datetime', 'nullable' => false, 'default' => null, 'extra' => '' ],
			'updated_at'               => [ 'type' => 'datetime', 'nullable' => false, 'default' => null, 'extra' => '' ],
			'resolved_at'              => [ 'type' => 'datetime', 'nullable' => true, 'default' => null, 'extra' => '' ],
		];
	}

	private function expected_indexes(): array
	{
		return [
			'PRIMARY'         => [ 'non_unique' => 0, 'type' => 'BTREE', 'usable' => true, 'columns' => [ [ 'name' => 'repair_key', 'prefix' => null ] ] ],
			'client_status'   => [ 'non_unique' => 1, 'type' => 'BTREE', 'usable' => true, 'columns' => [ [ 'name' => 'client_name', 'prefix' => null ], [ 'name' => 'status', 'prefix' => null ] ] ],
			'status_due'      => [ 'non_unique' => 1, 'type' => 'BTREE', 'usable' => true, 'columns' => [ [ 'name' => 'status', 'prefix' => null ], [ 'name' => 'next_attempt_at', 'prefix' => null ] ] ],
			'status_lease'    => [ 'non_unique' => 1, 'type' => 'BTREE', 'usable' => true, 'columns' => [ [ 'name' => 'status', 'prefix' => null ], [ 'name' => 'lease_expires_at', 'prefix' => null ] ] ],
			'status_resolved' => [ 'non_unique' => 1, 'type' => 'BTREE', 'usable' => true, 'columns' => [ [ 'name' => 'status', 'prefix' => null ], [ 'name' => 'resolved_at', 'prefix' => null ] ] ],
		];
	}

	private function ownership_option_is_autoloaded(): bool
	{
		global $wpdb;

		$autoload = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT `autoload` FROM `{$wpdb->options}` WHERE `option_name` = %s LIMIT 1",
				self::OWNERSHIP_OPTION
			)
		);
		$autoload_values = function_exists( 'wp_autoload_values_to_autoload' )
			? wp_autoload_values_to_autoload()
			: [ 'yes', 'on', 'auto-on', 'auto' ];

		return in_array( $autoload, $autoload_values, true );
	}

	private function reset_ledger_artifacts(): void
	{
		global $wpdb;

		delete_option( self::OWNERSHIP_OPTION );
		wp_cache_delete( self::OWNERSHIP_OPTION, 'options' );
		$wpdb->query( "DROP TABLE IF EXISTS `{$this->table}`" );
		unset( $wpdb->{self::TABLE_KEY} );
		$wpdb->tables = $this->wpdb_tables_before_test;
	}

	public function preserve_real_repair_ledger_table( string $query ): string
	{
		$table = preg_quote( $this->table, '/' );
		$query = (string) preg_replace(
			'/^CREATE\s+TEMPORARY\s+TABLE\s+`' . $table . '`/i',
			'CREATE TABLE `' . $this->table . '`',
			$query
		);

		return (string) preg_replace(
			'/^DROP\s+TEMPORARY\s+TABLE(\s+IF\s+EXISTS)?\s+`' . $table . '`/i',
			'DROP TABLE$1 `' . $this->table . '`',
			$query
		);
	}

	private function table_exists( string $table ): bool
	{
		global $wpdb;

		$suppress = $wpdb->suppress_errors();
		try {
			$columns = $wpdb->get_col( 'SHOW COLUMNS FROM `' . str_replace( '`', '``', $table ) . '`' );
		} finally {
			$wpdb->suppress_errors( $suppress );
		}

		return is_array( $columns ) && [] !== $columns;
	}

	private function table_engine( string $table ): string
	{
		global $wpdb;

		$definition = $wpdb->get_row(
			'SHOW CREATE TABLE `' . str_replace( '`', '``', $table ) . '`',
			ARRAY_N
		);

		return is_array( $definition ) && isset( $definition[1] ) &&
			preg_match( '/\bENGINE=([A-Za-z0-9_]+)/i', $definition[1], $matches )
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
		$rows    = $wpdb->get_results(
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

	private function record_queries( callable $operation ): array
	{
		$queries  = [];
		$recorder = static function ( string $query ) use ( &$queries ): string {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $recorder );
		try {
			$operation();
		} finally {
			remove_filter( 'query', $recorder );
		}

		return $queries;
	}

	private function ddl_queries( array $queries ): array
	{
		return array_values(
			array_filter(
				$queries,
				static function ( string $query ): bool {
					return 1 === preg_match(
						'/^\s*(?:CREATE(?:\s+TEMPORARY)?|ALTER|DROP(?:\s+TEMPORARY)?|RENAME|TRUNCATE)\s+TABLE\b/i',
						$query
					);
				}
			)
		);
	}

	private function alter_or_drop_queries( array $queries ): array
	{
		return array_values(
			array_filter(
				$queries,
				static function ( string $query ): bool {
					return 1 === preg_match(
						'/^\s*(?:ALTER|DROP(?:\s+TEMPORARY)?|RENAME|TRUNCATE)\s+TABLE\b/i',
						$query
					);
				}
			)
		);
	}

	private function create_table_queries( array $queries, string $table ): array
	{
		return array_values(
			array_filter(
				$queries,
				static function ( string $query ) use ( $table ): bool {
					return 1 === preg_match(
						'/^\s*CREATE(?:\s+TEMPORARY)?\s+TABLE\s+`?' . preg_quote( $table, '/' ) . '`?\b/i',
						$query
					);
				}
			)
		);
	}

	private function ledger_access_queries( array $queries ): array
	{
		return array_values(
			array_filter(
				$queries,
				static function ( string $query ): bool {
					return false !== strpos( $query, self::TABLE_KEY ) ||
						false !== strpos( $query, self::OWNERSHIP_OPTION );
				}
			)
		);
	}

	private function assert_failure( ?Throwable $failure, string $message_fragment ): void
	{
		self::assertNotNull( $failure, 'The unsafe ledger state must fail closed.' );
		self::assertNotInstanceOf( Error::class, $failure );
		self::assertStringContainsStringIgnoringCase( $message_fragment, $failure->getMessage() );
	}
}
