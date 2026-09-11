<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Abstracts\Connection as AbstractConnection;
use iTRON\wpConnections\Abstracts\Storage;
use iTRON\wpConnections\Client;
use iTRON\wpConnections\ConnectionCollection;
use iTRON\wpConnections\Exceptions\ClientRegisterFail;
use iTRON\wpConnections\Meta;
use iTRON\wpConnections\MetaCollection;
use iTRON\wpConnections\Query\Connection as ConnectionQuery;
use iTRON\wpConnections\Query\MetaCollection as MetaQueryCollection;
use iTRON\wpConnections\Query\Relation as RelationQuery;
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

	public function test_non_string_sanitize_result_is_an_unsafe_normalization_error_before_factory(): void
	{
		$factory_calls = 0;
		$sanitize_filter = static function () {
			return [];
		};
		$factory_filter = static function ( string $class ) use ( &$factory_calls ): string {
			$factory_calls++;
			return $class;
		};
		add_filter( 'sanitize_title', $sanitize_filter );
		add_filter( 'wpConnections/factory/getStorage/class', $factory_filter );

		try {
			$this->assert_client_registration_error(
				'Client name is empty or unsafe after normalization.',
				static function (): void {
					new Client( 'unsafe-filter-result' );
				}
			);
		} finally {
			remove_filter( 'wpConnections/factory/getStorage/class', $factory_filter );
			remove_filter( 'sanitize_title', $sanitize_filter );
		}

		self::assertSame( 0, $factory_calls );
	}

	public function test_legacy_deleted_post_callback_identity_remains_removable_in_1_x_bridge(): void
	{
		$client   = $this->new_default_client( 'legacy-delete-callback' );
		$callback = [ $client->getStorage(), 'deleteByObjectID' ];

		self::assertSame( 10, has_action( 'deleted_post', $callback ) );
		self::assertTrue( remove_action( 'deleted_post', $callback ) );
		self::assertFalse( has_action( 'deleted_post', $callback ) );
	}

	public function test_semantic_post_deletion_lifecycle_is_idempotent_and_legacy_removable(): void
	{
		global $wp_filter;

		$client   = $this->new_default_client( 'semantic-delete-callback' );
		$callback = [ $client->getStorage(), 'deleteByObjectID' ];

		$client->disablePostDeletionCleanup();
		$client->disablePostDeletionCleanup();
		self::assertFalse( has_action( 'deleted_post', $callback ) );

		$client->enablePostDeletionCleanup();
		$client->enablePostDeletionCleanup();
		self::assertSame( 10, has_action( 'deleted_post', $callback ) );

		$callback_id = _wp_filter_build_unique_id( 'deleted_post', $callback, 10 );
		self::assertSame( 1, $wp_filter['deleted_post']->callbacks[10][ $callback_id ]['accepted_args'] );
		self::assertTrue( remove_action( 'deleted_post', $callback, 10 ) );
		self::assertFalse( has_action( 'deleted_post', $callback ) );

		$client->enablePostDeletionCleanup();
		self::assertSame( 10, has_action( 'deleted_post', $callback ) );
	}

	public function test_semantic_post_deletion_lifecycle_controls_real_cleanup_once(): void
	{
		$client   = $this->new_default_client( 'semantic-delete-behavior', true );
		$relation = $this->register_relation( $client, 'semantic-delete-relation' );
		$page_one = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$post_one = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$page_two = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$post_two = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$first    = $relation->createConnection(
			$this->connection_query( $page_one, $post_one, 'disabled', 'first' )
		);
		$second   = $relation->createConnection(
			$this->connection_query( $page_two, $post_two, 'enabled', 'second' )
		);

		$client->disablePostDeletionCleanup();
		$client->disablePostDeletionCleanup();
		self::assertInstanceOf( \WP_Post::class, wp_delete_post( $post_one, true ) );
		self::assertSame( 1, $this->find_connection_count( $relation, $first->id ) );

		$cleanup_calls = 0;
		$cleanup_hook  = static function ( Client $hook_client ) use ( $client, &$cleanup_calls ): void {
			if ( $hook_client === $client ) {
				$cleanup_calls++;
			}
		};
		add_action( 'wpConnections/storage/deleteByObjectID', $cleanup_hook );
		try {
			$client->enablePostDeletionCleanup();
			$client->enablePostDeletionCleanup();
			self::assertInstanceOf( \WP_Post::class, wp_delete_post( $post_two, true ) );
		} finally {
			remove_action( 'wpConnections/storage/deleteByObjectID', $cleanup_hook );
		}

		self::assertSame( 1, $cleanup_calls );
		self::assertSame( 1, $this->find_connection_count( $relation, $first->id ) );
		self::assertSame( 0, $this->find_connection_count( $relation, $second->id ) );
	}

	public function test_default_storage_rejects_collision_and_65_character_identifier(): void
	{
		global $wpdb;

		$options_before = $this->option_names();
		$first = $this->new_default_client( 'my-client' );
		self::assertSame( 'post_connections_my_client', $first->getStorage()->get_connections_table() );
		self::assertSame( 'post_connections_meta_my_client', $first->getStorage()->get_meta_table() );
		$claim_options = array_values( array_diff( $this->option_names(), $options_before ) );
		self::assertCount( 1, $claim_options );
		$claim = get_option( $claim_options[0] );
		self::assertSame( 1, $claim['version'] ?? null );
		self::assertSame( 'my_client', $claim['postfix'] ?? null );
		self::assertSame( 'my-client', $claim['owner'] ?? null );
		self::assertNotContains(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
					$claim_options[0]
				)
			),
			[ 'yes', 'on', 'auto', 'auto-on' ],
			true
		);

		$same_owner = $this->new_default_client( 'MY CLIENT' );
		self::assertSame( $first->getName(), $same_owner->getName() );
		self::assertCount(
			1,
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name = %s",
					$claim_options[0]
				)
			)
		);

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
		self::assertSame(
			'post_connections_cf7_telegram',
			$this->new_default_client( 'cf7-telegram' )->getStorage()->get_connections_table()
		);
		self::assertSame(
			'post_connections_cf7_vk',
			$this->new_default_client( 'cf7-vk' )->getStorage()->get_connections_table()
		);
		self::assertSame(
			'post_connections_neural_seo',
			$this->new_default_client( 'neural_seo' )->getStorage()->get_connections_table()
		);

		$tables_at_boundary = $wpdb->tables;
		$options_at_boundary = $this->option_names();
		$this->assert_client_registration_error(
			'Client table identifier exceeds the 64-character database limit.',
			function () use ( $maximum ): void {
				$this->new_default_client( str_repeat( 'b', $maximum + 1 ) );
			}
		);
		self::assertSame( $tables_at_boundary, $wpdb->tables );
		self::assertSame( $options_at_boundary, $this->option_names() );
	}

	public function test_long_prefix_uses_the_same_64_character_complete_identifier_budget(): void
	{
		global $wpdb;

		$wpdb->prefix = str_repeat( 'p', 41 );
		$boundary = $this->new_default_client( 'z' );
		self::assertSame( 64, strlen( $wpdb->prefix . $boundary->getStorage()->get_meta_table() ) );

		$this->assert_client_registration_error(
			'Client table identifier exceeds the 64-character database limit.',
			function (): void {
				$this->new_default_client( 'zz' );
			}
		);

		$wpdb->prefix = str_repeat( 'q', 42 );
		$this->assert_client_registration_error(
			'Client table identifier exceeds the 64-character database limit.',
			function (): void {
				$this->new_default_client( 'x' );
			}
		);
	}

	public function test_concurrent_same_owner_claim_reloads_the_persisted_winner(): void
	{
		global $wpdb;

		$option_name = 'wpconnections_storage_owner_' . hash( 'sha256', 'race_client' );
		$record = [
			'version' => 1,
			'postfix' => 'race_client',
			'owner'   => 'race-client',
		];
		$injected = false;
		$query_filter = function ( string $query ) use (
			&$query_filter,
			&$injected,
			$option_name,
			$record
		): string {
			if (
				! $injected &&
				0 === stripos( $query, 'INSERT INTO' ) &&
				false !== strpos( $query, $option_name )
			) {
				$injected = true;
				remove_filter( 'query', $query_filter );
				add_option( $option_name, $record, '', false );
			}

			return $query;
		};
		add_filter( 'query', $query_filter );

		try {
			$client = $this->new_default_client( 'race-client' );
		} finally {
			remove_filter( 'query', $query_filter );
		}

		self::assertTrue( $injected );
		self::assertSame( $record, get_option( $option_name ) );
		self::assertSame( 'race-client', $client->getName() );
		self::assertSame( 'post_connections_race_client', $client->getStorage()->get_connections_table() );
		self::assertSame(
			1,
			(int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s",
					$option_name
				)
			)
		);
	}

	public function test_complete_legacy_pair_requires_matching_explicit_ownership_record(): void
	{
		global $wpdb;

		[ $option_name, $record, $seed ] = $this->capture_empty_mapping_claim( 'legacy-client' );
		$this->unregister_storage( $seed->getStorage() );
		delete_option( $option_name );
		$this->create_legacy_pair( 'legacy_client' );

		$registered_before = $wpdb->tables;
		$this->assert_client_registration_error(
			'Client table ownership is ambiguous; explicit migration is required.',
			function (): void {
				$this->new_default_client( 'legacy-client' );
			}
		);
		self::assertSame( $registered_before, $wpdb->tables );
		self::assertTrue( $this->table_exists( $wpdb->prefix . 'post_connections_legacy_client' ) );
		self::assertTrue( $this->table_exists( $wpdb->prefix . 'post_connections_meta_legacy_client' ) );

		self::assertTrue( add_option( $option_name, $record, '', false ) );
		$adopted = $this->new_default_client( 'legacy-client' );
		self::assertSame( 'post_connections_legacy_client', $adopted->getStorage()->get_connections_table() );
		self::assertTrue( $this->table_exists( $wpdb->prefix . $adopted->getStorage()->get_meta_table() ) );
	}

	public function test_partial_or_malformed_legacy_mapping_is_never_repaired_implicitly(): void
	{
		global $wpdb;

		[ $option_name, $record, $seed ] = $this->capture_empty_mapping_claim( 'partial-client' );
		$this->unregister_storage( $seed->getStorage() );
		$this->create_legacy_pair( 'partial_client', true, false );

		$this->assert_client_registration_error(
			'Client table ownership is ambiguous; explicit migration is required.',
			function (): void {
				$this->new_default_client( 'partial-client', true );
			}
		);
		self::assertTrue( $this->table_exists( $wpdb->prefix . 'post_connections_partial_client' ) );
		self::assertFalse( $this->table_exists( $wpdb->prefix . 'post_connections_meta_partial_client' ) );

		$this->drop_legacy_pair( 'partial_client' );
		update_option(
			$option_name,
			[ 'version' => 99, 'postfix' => $record['postfix'], 'owner' => $record['owner'] ],
			false
		);
		$this->assert_client_registration_error(
			'Client table ownership is ambiguous; explicit migration is required.',
			function (): void {
				$this->new_default_client( 'partial-client' );
			}
		);

		foreach ( [ '', 'unsafe%owner' ] as $malformed_owner ) {
			update_option(
				$option_name,
				[ 'version' => 1, 'postfix' => $record['postfix'], 'owner' => $malformed_owner ],
				false
			);
			$this->assert_client_registration_error(
				'Client table ownership is ambiguous; explicit migration is required.',
				function (): void {
					$this->new_default_client( 'partial-client' );
				}
			);
		}
	}

	public function test_two_default_clients_isolate_crud_metadata_and_every_delete_selector(): void
	{
		$first  = $this->new_default_client( 'isolation-first', true );
		$second = $this->new_default_client( 'isolation-second', true );
		$first_relation  = $this->register_relation( $first, 'isolation-relation' );
		$second_relation = $this->register_relation( $second, 'isolation-relation' );

		$pages = [];
		$posts = [];
		for ( $index = 0; $index < 6; $index++ ) {
			$pages[] = self::factory()->post->create( [ 'post_type' => 'page' ] );
			$posts[] = self::factory()->post->create( [ 'post_type' => 'post' ] );
		}

		$first_connections  = [];
		$second_connections = [];
		foreach ( $pages as $index => $page_id ) {
			$first_connections[] = $first_relation->createConnection(
				$this->connection_query( $page_id, $posts[ $index ], 'first', 'first-' . $index )
			);
			$second_connections[] = $second_relation->createConnection(
				$this->connection_query( $page_id, $posts[ $index ], 'second', 'second-' . $index )
			);
		}

		self::assertNotSame(
			$first->getStorage()->get_connections_table(),
			$second->getStorage()->get_connections_table()
		);
		self::assertCount( 6, $first_relation->findConnections() );
		self::assertCount( 6, $second_relation->findConnections() );

		$first_connections[0]->title = 'first-updated';
		$first_connections[0]->meta->add( new Meta( 'updated', 'yes' ) );
		$first_connections[0]->update();
		self::assertSame(
			'first-updated',
			$this->find_connection( $first_relation, $first_connections[0]->id )->title
		);
		self::assertSame(
			'second',
			$this->find_connection( $second_relation, $second_connections[0]->id )->title
		);

		$meta_delete = new ConnectionQuery();
		$meta_delete->set( 'id', $first_connections[0]->id );
		$meta_delete->meta->fromArray( [ [ 'key' => 'owner' ] ] );
		self::assertSame( 1, $first_relation->removeConnectionMeta( $meta_delete ) );
		self::assertSame(
			[ 'owner' => [ 'second-0' ] ],
			$this->find_connection( $second_relation, $second_connections[0]->id )->meta->toArray()
		);

		$specific = new ConnectionQuery();
		$specific->set( 'id', $first_connections[1]->id );
		self::assertSame( 1, $first_relation->detachConnections( $specific ) );

		self::assertSame(
			1,
			$first_relation->detachConnections( new ConnectionQuery( $pages[2], $posts[2] ) )
		);
		self::assertSame( 1, $first_relation->detachConnections( new ConnectionQuery( $pages[3] ) ) );

		$to = new ConnectionQuery();
		$to->set( 'to', $posts[4] );
		self::assertSame( 1, $first_relation->detachConnections( $to ) );

		$both = new ConnectionQuery();
		$both->set( 'both', $pages[5] );
		self::assertSame( 1, $first_relation->detachConnections( $both ) );

		self::assertCount( 1, $first_relation->findConnections() );
		self::assertCount( 6, $second_relation->findConnections() );
		foreach ( $second_connections as $connection ) {
			self::assertSame(
				1,
				$this->find_connection_count( $second_relation, $connection->id )
			);
		}
	}

	public function test_default_storage_is_prefix_bound_and_fresh_client_uses_new_prefix(): void
	{
		global $wpdb;

		$post_id             = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$post                = get_post( $post_id );
		$bound               = $this->new_default_client( 'site-bound' );
		$stale_storage_calls = 0;
		$nested_result       = null;
		$storage_hook        = static function ( Client $client ) use ( $bound, &$stale_storage_calls ): void {
			if ( $client === $bound ) {
				$stale_storage_calls++;
			}
		};
		$nested_call         = static function ( int $deleted_post_id ) use ( $bound, &$nested_result ): void {
			$nested_result = $bound->getStorage()->deleteByObjectID( $deleted_post_id );
		};
		$wpdb->prefix = 'alternate_';

		$this->assert_client_registration_error(
			'Client storage is bound to a different WordPress site prefix.',
			static function () use ( $bound ): void {
				$bound->getStorage()->findConnections( new ConnectionQuery( 1, 2 ) );
			}
		);
		add_action( 'wpConnections/storage/deleteByObjectID', $storage_hook );
		add_action( 'deleted_post', $nested_call, 9 );
		$queries_before = $wpdb->num_queries;
		try {
			do_action( 'deleted_post', $post_id, $post );
		} finally {
			remove_action( 'deleted_post', $nested_call, 9 );
			remove_action( 'wpConnections/storage/deleteByObjectID', $storage_hook );
		}
		self::assertSame( 0, $nested_result );
		self::assertSame( 0, $stale_storage_calls );
		self::assertSame( $queries_before, $wpdb->num_queries );

		$fresh = $this->new_default_client( 'site-fresh' );
		self::assertSame(
			'alternate_post_connections_site_fresh',
			$wpdb->prefix . $fresh->getStorage()->get_connections_table()
		);

		$wpdb->prefix = $this->original_prefix;
		if ( ! is_multisite() ) {
			return;
		}

		$site_one_client = $this->new_default_client( 'site-cascade', true );
		$site_one_relation = $this->register_relation( $site_one_client, 'site-cascade-relation' );
		$site_one_page = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$site_one_post = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$site_one_relation->createConnection( new ConnectionQuery( $site_one_page, $site_one_post ) );
		self::assertCount( 1, $site_one_relation->findConnections() );

		$site_id = self::factory()->blog->create();
		switch_to_blog( $site_id );
		try {
			$this->assert_client_registration_error(
				'Client storage is bound to a different WordPress site prefix.',
				static function () use ( $site_one_client ): void {
					$site_one_client->getStorage()->deleteByObjectID( 1 );
				}
			);

			$site_client = $this->new_default_client( 'site-cascade', true );
			$site_relation = $this->register_relation( $site_client, 'site-cascade-relation' );
			$site_page = self::factory()->post->create( [ 'post_type' => 'page' ] );
			$site_post = self::factory()->post->create( [ 'post_type' => 'post' ] );
			$site_relation->createConnection( new ConnectionQuery( $site_page, $site_post ) );
			self::assertCount( 1, $site_relation->findConnections() );

			$cleanup_clients = [];
			$cleanup_hook    = static function ( Client $client ) use ( &$cleanup_clients ): void {
				$cleanup_clients[] = spl_object_id( $client );
			};
			add_action( 'wpConnections/storage/deleteByObjectID', $cleanup_hook );
			try {
				self::assertInstanceOf( \WP_Post::class, wp_delete_post( $site_post, true ) );
			} finally {
				remove_action( 'wpConnections/storage/deleteByObjectID', $cleanup_hook );
			}
			self::assertSame( [ spl_object_id( $site_client ) ], $cleanup_clients );
			self::assertCount( 0, $site_relation->findConnections() );
		} finally {
			restore_current_blog();
		}

		self::assertCount( 1, $site_one_relation->findConnections() );
	}

	public function test_prefix_change_inside_storage_callbacks_stops_before_registration_or_dml(): void
	{
		global $wpdb;

		$prefix = $wpdb->prefix;
		$tables_before = $wpdb->tables;
		$install_filter = static function () use ( $wpdb ): bool {
			$wpdb->prefix = 'callback_';
			return true;
		};
		add_filter( 'wpConnections/storage/installOnInit', $install_filter, 999, 2 );

		try {
			$this->assert_client_registration_error(
				'Client storage is bound to a different WordPress site prefix.',
				static function (): void {
					new Client( 'callback-init' );
				}
			);
		} finally {
			remove_filter( 'wpConnections/storage/installOnInit', $install_filter, 999 );
			$wpdb->prefix = $prefix;
		}
		self::assertSame( $tables_before, $wpdb->tables );

		$client = $this->new_default_client( 'callback-create', true );
		$storage = $client->getStorage();
		$table = $wpdb->prefix . $storage->get_connections_table();
		$rows_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$attempt_hook = static function () use ( $wpdb ): void {
			$wpdb->prefix = 'callback_';
		};
		add_action( 'iTRON/wpConnections/storage/createConnection/attempt', $attempt_hook );

		try {
			$query = new ConnectionQuery( 1, 2 );
			$query->set( 'relation', 'callback' );
			$this->assert_client_registration_error(
				'Client storage is bound to a different WordPress site prefix.',
				static function () use ( $storage, $query ): void {
					$storage->createConnection( $query );
				}
			);
		} finally {
			remove_action( 'iTRON/wpConnections/storage/createConnection/attempt', $attempt_hook );
			$wpdb->prefix = $prefix;
		}

		self::assertSame( $rows_before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) );
	}

	public function test_terminal_after_hook_prefix_change_does_not_turn_a_committed_write_into_failure(): void
	{
		global $wpdb;

		$prefix = $wpdb->prefix;
		$client = $this->new_default_client( 'callback-after', true );
		$storage = $client->getStorage();
		$query = new ConnectionQuery( 1, 2 );
		$query->set( 'relation', 'callback' );
		$connection_id = $storage->createConnection( $query );
		$meta = new MetaCollection();
		$meta->add( new Meta( 'after-hook', 'persisted' ) );
		$after_hook = static function () use ( $wpdb ): void {
			$wpdb->prefix = 'callback_';
		};
		add_action( 'wpConnections/storage/addConnectionMeta/after', $after_hook );

		try {
			$storage->addConnectionMeta( $connection_id, $meta );
		} finally {
			remove_action( 'wpConnections/storage/addConnectionMeta/after', $after_hook );
			$wpdb->prefix = $prefix;
		}

		self::assertSame(
			1,
			(int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . $wpdb->prefix . $storage->get_meta_table() .
					' WHERE connection_id = %d AND meta_key = %s AND meta_value = %s',
					$connection_id,
					'after-hook',
					'persisted'
				)
			)
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
			$callback = [ $client->getStorage(), 'deleteByObjectID' ];
			self::assertSame( 10, has_action( 'deleted_post', $callback ) );
			$client->disablePostDeletionCleanup();
			self::assertFalse( has_action( 'deleted_post', $callback ) );
			$client->enablePostDeletionCleanup();
			self::assertSame( 10, has_action( 'deleted_post', $callback ) );

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

	private function new_default_client( string $name, bool $install = false ): Client
	{
		$install_filter = static function () use ( $install ): bool {
			return $install;
		};
		add_filter( 'wpConnections/storage/installOnInit', $install_filter, 999, 2 );
		try {
			$client = $this->remember_client( new Client( $name ) );
		} finally {
			remove_filter( 'wpConnections/storage/installOnInit', $install_filter, 999 );
		}

		global $wpdb;
		if ( $client->getStorage() instanceof WPStorage ) {
			foreach ( [
				$wpdb->prefix . $client->getStorage()->get_connections_table(),
				$wpdb->prefix . $client->getStorage()->get_meta_table(),
			] as $table ) {
				if ( strlen( $table ) <= 64 ) {
					$this->physical_tables[] = $table;
				}
			}
		}

		return $client;
	}

	private function capture_empty_mapping_claim( string $name ): array
	{
		$options_before = $this->option_names();
		$client = $this->new_default_client( $name );
		$new_options = array_values( array_diff( $this->option_names(), $options_before ) );

		self::assertCount( 1, $new_options );
		$record = get_option( $new_options[0] );
		self::assertIsArray( $record );

		return [ $new_options[0], $record, $client ];
	}

	private function unregister_storage( Storage $storage ): void
	{
		if ( ! $storage instanceof WPStorage ) {
			return;
		}

		global $wpdb;
		foreach ( [ $storage->get_connections_table(), $storage->get_meta_table() ] as $key ) {
			$wpdb->tables = array_values( array_filter(
				$wpdb->tables,
				static function ( string $registered ) use ( $key ): bool {
					return $key !== $registered;
				}
			) );
			unset( $wpdb->{$key} );
		}
	}

	private function create_legacy_pair(
		string $postfix,
		bool $connections = true,
		bool $meta = true
	): void {
		global $wpdb;

		$connections_table = $wpdb->prefix . WPStorage::CONNECTIONS_TABLE_PREFIX . $postfix;
		$meta_table = $wpdb->prefix . WPStorage::META_TABLE_PREFIX . $postfix;
		$this->physical_tables[] = $connections_table;
		$this->physical_tables[] = $meta_table;

		if ( $connections ) {
			$wpdb->query(
				"CREATE TABLE `{$connections_table}` (
					`ID` bigint(20) unsigned NOT NULL auto_increment,
					`relation` varchar(255) NOT NULL,
					`from` bigint(20) unsigned NOT NULL,
					`to` bigint(20) unsigned NOT NULL,
					`order` bigint(20) unsigned NULL default '0',
					`title` varchar(63) NULL default '',
					PRIMARY KEY (`ID`)
				)"
			);
		}

		if ( $meta ) {
			$wpdb->query(
				"CREATE TABLE `{$meta_table}` (
					`meta_id` bigint(20) unsigned NOT NULL auto_increment,
					`connection_id` bigint(20) unsigned NOT NULL default '0',
					`meta_key` varchar(255) NOT NULL,
					`meta_value` longtext NOT NULL,
					PRIMARY KEY (`meta_id`)
				)"
			);
		}
	}

	private function drop_legacy_pair( string $postfix ): void
	{
		global $wpdb;
		foreach ( [ WPStorage::META_TABLE_PREFIX, WPStorage::CONNECTIONS_TABLE_PREFIX ] as $prefix ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$prefix}{$postfix}`" );
		}
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

	private function option_names(): array
	{
		global $wpdb;
		return $wpdb->get_col( "SELECT option_name FROM {$wpdb->options}" );
	}

	private function register_relation( Client $client, string $name ): \iTRON\wpConnections\Relation
	{
		$query = new RelationQuery();
		$query->set( 'name', $name );
		$query->set( 'from', 'page' );
		$query->set( 'to', 'post' );
		$query->set( 'cardinality', 'm-m' );
		$query->set( 'duplicatable', true );
		$query->set( 'closurable', false );

		return $client->registerRelation( $query );
	}

	private function connection_query( int $from, int $to, string $title, string $owner ): ConnectionQuery
	{
		$query = new ConnectionQuery( $from, $to );
		$query->set( 'title', $title );
		$query->set( 'order', 0 );
		$query->meta->fromArray( [ [ 'key' => 'owner', 'value' => $owner ] ] );
		return $query;
	}

	private function find_connection(
		\iTRON\wpConnections\Relation $relation,
		int $connection_id
	): \iTRON\wpConnections\Connection {
		$query = new ConnectionQuery();
		$query->set( 'id', $connection_id );
		return $relation->findConnections( $query )->first();
	}

	private function find_connection_count(
		\iTRON\wpConnections\Relation $relation,
		int $connection_id
	): int {
		$query = new ConnectionQuery();
		$query->set( 'id', $connection_id );
		return count( $relation->findConnections( $query ) );
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
			self::assertSame( ClientRegisterFail::class, get_class( $exception ), $exception->getMessage() );
			self::assertSame( 4, $exception->getCode() );
			self::assertSame( $message, $exception->getMessage() );
			return;
		}

		self::fail( 'Expected ClientRegisterFail.' );
	}
}
