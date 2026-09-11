<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Abstracts\Connection as AbstractConnection;
use iTRON\wpConnections\Abstracts\Storage;
use iTRON\wpConnections\Client;
use iTRON\wpConnections\Connection;
use iTRON\wpConnections\ConnectionCollection;
use iTRON\wpConnections\EntityResolution;
use iTRON\wpConnections\EntityResolverInterface;
use iTRON\wpConnections\Exceptions\ClientRegisterFail;
use iTRON\wpConnections\Exceptions\ConnectionEndpointInvalid;
use iTRON\wpConnections\Exceptions\ConnectionEndpointNotFound;
use iTRON\wpConnections\Exceptions\ConnectionEndpointResolverFail;
use iTRON\wpConnections\Exceptions\ConnectionEndpointTypeMismatch;
use iTRON\wpConnections\Exceptions\ConnectionEndpointTypeUnsupported;
use iTRON\wpConnections\Exceptions\ConnectionNotFound;
use iTRON\wpConnections\Exceptions\ConnectionRelationMismatch;
use iTRON\wpConnections\Exceptions\ConnectionWrongData;
use iTRON\wpConnections\Internal\RestRouteRegistry;
use iTRON\wpConnections\Meta;
use iTRON\wpConnections\MetaCollection;
use iTRON\wpConnections\Query\Connection as ConnectionQuery;
use iTRON\wpConnections\Query\MetaCollection as MetaQueryCollection;
use iTRON\wpConnections\Query\Relation as RelationQuery;

class EntityValidationRecordingStorage extends Storage
{
	public static array $created = [];
	public static array $updated = [];
	public static array $added_meta = [];
	public static array $removed_meta = [];
	public static ?ConnectionCollection $found = null;
	public static bool $update_result = true;
	private Client $client;

	public function __construct( Client $client )
	{
		$this->client = $client;
	}

	public static function use_for_test( string $storage_class, Client $client ): string
	{
		return self::class;
	}

	public static function reset(): void
	{
		self::$created = [];
		self::$updated = [];
		self::$added_meta = [];
		self::$removed_meta = [];
		self::$found   = null;
		self::$update_result = true;
	}

	public function createConnection( ConnectionQuery $connection_query ): int
	{
		self::$created[] = clone $connection_query;

		return 700 + count( self::$created );
	}

	public function updateConnection( AbstractConnection $connection ): bool
	{
		self::$updated[] = clone $connection;

		return self::$update_result;
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
		return self::$found ?? new ConnectionCollection();
	}

	public function addConnectionMeta( int $object_id, MetaCollection $meta_collection ): void
	{
		self::$added_meta[] = [ $object_id, clone $meta_collection ];
	}

	public function removeConnectionMeta( int $object_id, MetaQueryCollection $meta_query )
	{
		self::$removed_meta[] = [ $object_id, clone $meta_query ];

		return 0;
	}
}

/**
 * CORE-04 critical-scenario evidence mapping:
 *
 * - ENT-VAL-01: post/CPT create, effective-state update, legacy cleanup.
 * - ENT-EXT-01 / CLIENT-ISO-01 / FACTORY-EXT-01: typed resolver and storage boundary.
 * - CARD-MUT-01: entity-before-closure/duplicate/cardinality precedence.
 * - ERR-CODE-01: stable 305-310 domain errors and physical-side order.
 * - REST-ERROR-01: full-dispatch create/update delegation without HTTP remapping.
 * - HOOK-CONTRACT-01: initial rejection emits no hook; transformed candidates
 *   are conditionally revalidated and never emit a false created hook.
 *
 * CLIENT/FACTORY/REST/HOOK rows are scoped contributions; their remaining
 * registry expectations stay with the other delivery tasks named in
 * docs/test-quality.md.
 */
class EntityValidationTest extends WPConnectionsTestCase
{
	private array $additional_clients = [];

	public function tear_down()
	{
		if ( class_exists( RestRouteRegistry::class ) ) {
			foreach ( $this->additional_clients as $client ) {
				RestRouteRegistry::instance()->deactivateClient( $client );
			}
		}
		remove_filter(
			'wpConnections/factory/getStorage/class',
			[ EntityValidationRecordingStorage::class, 'use_for_test' ]
		);
		EntityValidationRecordingStorage::reset();
		parent::tear_down();
	}

	public function test_accepts_every_extant_post_status_and_registered_cpt(): void
	{
		register_post_type( 'core04_book', [ 'public' => false ] );
		$relation = $this->register_relation(
			'entity-post-status',
			'page',
			'core04_book',
			[ 'duplicatable' => true ]
		);

		foreach ( [ 'draft', 'private', 'trash' ] as $status ) {
			$book_id = self::factory()->post->create(
				[
					'post_title'  => 'Book ' . $status,
					'post_status' => $status,
					'post_type'   => 'core04_book',
				]
			);
			$connection = $relation->createConnection(
				new ConnectionQuery( $this->page_ids[0], $book_id )
			);

			self::assertGreaterThan( 0, $connection->id );
		}

		unregister_post_type( 'core04_book' );
	}

	public function test_rejects_invalid_missing_and_wrong_type_endpoints_in_from_then_to_order(): void
	{
		$relation = $this->register_relation( 'entity-error-order', 'page', 'post' );

		$this->assert_connection_error(
			305,
			'Invalid connection endpoint ID: from=-1.',
			static function () use ( $relation ): void {
				$relation->createConnection( new ConnectionQuery( -1, 1 ) );
			}
		);
		$this->assert_connection_error(
			305,
			'Invalid connection endpoint ID: to=-2.',
			function () use ( $relation ): void {
				$relation->createConnection( new ConnectionQuery( $this->page_ids[0], -2 ) );
			}
		);
		$this->assert_connection_error(
			306,
			'Connection endpoint entity not found: from=999999999.',
			static function () use ( $relation ): void {
				$relation->createConnection( new ConnectionQuery( 999999999, 999999998 ) );
			}
		);
		$this->assert_connection_error(
			306,
			'Connection endpoint entity not found: to=999999998.',
			function () use ( $relation ): void {
				$relation->createConnection( new ConnectionQuery( $this->page_ids[0], 999999998 ) );
			}
		);

		$deleted_page = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$deleted_post = self::factory()->post->create( [ 'post_type' => 'post' ] );
		wp_delete_post( $deleted_page, true );
		wp_delete_post( $deleted_post, true );
		$this->assert_connection_error(
			306,
			'Connection endpoint entity not found: from=' . $deleted_page . '.',
			static function () use ( $relation, $deleted_page ): void {
				$relation->createConnection( new ConnectionQuery( $deleted_page, 1 ) );
			}
		);
		$this->assert_connection_error(
			306,
			'Connection endpoint entity not found: to=' . $deleted_post . '.',
			function () use ( $relation, $deleted_post ): void {
				$relation->createConnection( new ConnectionQuery( $this->page_ids[0], $deleted_post ) );
			}
		);
		$this->assert_connection_error(
			307,
			'Connection endpoint type mismatch: from expected page, got post.',
			function () use ( $relation ): void {
				$relation->createConnection( new ConnectionQuery( $this->post_ids[0], $this->post_ids[1] ) );
			}
		);
		$this->assert_connection_error(
			307,
			'Connection endpoint type mismatch: to expected post, got page.',
			function () use ( $relation ): void {
				$relation->createConnection( new ConnectionQuery( $this->page_ids[0], $this->page_ids[0] ) );
			}
		);
		$this->assert_connection_error(
			305,
			'Invalid connection endpoint ID: from=-1.',
			static function () use ( $relation ): void {
				$relation->createConnection( new ConnectionQuery( -1, -2 ) );
			}
		);

		self::assertCount( 0, $relation->findConnections() );
	}

	public function test_entity_failure_precedes_existing_invariants_and_emits_no_create_hooks(): void
	{
		$relation = $this->register_relation(
			'entity-before-invariants',
			'post',
			'post',
			[
				'cardinality' => '1-1',
				'closurable'  => false,
			]
		);
		$creating_calls = 0;
		$created_calls  = 0;
		$creating       = static function () use ( &$creating_calls ): void {
			$creating_calls++;
		};
		$created        = static function () use ( &$created_calls ): void {
			$created_calls++;
		};
		add_action( 'wpConnections/relation/creating', $creating );
		add_action( 'wpConnections/relation/created', $created );

		try {
			$this->assert_connection_error(
				306,
				'Connection endpoint entity not found: from=999999999.',
				static function () use ( $relation ): void {
					$relation->createConnection( new ConnectionQuery( 999999999, 999999999 ) );
				}
			);
		} finally {
			remove_action( 'wpConnections/relation/creating', $creating );
			remove_action( 'wpConnections/relation/created', $created );
		}

		self::assertSame( 0, $creating_calls );
		self::assertSame( 0, $created_calls );
		self::assertCount( 0, $relation->findConnections() );
	}

	public function test_creating_hook_cannot_change_relation_identity(): void
	{
		$client   = $this->recording_client( 'entity-hook-relation' );
		$relation = $this->register_relation_on( $client, 'entity-hook-owner', 'page', 'post' );
		$created_calls = 0;
		$creating = static function ( ConnectionQuery $query ): void {
			$query->relation = 'entity-hook-other';
		};
		$created = static function () use ( &$created_calls ): void {
			$created_calls++;
		};
		add_action( 'wpConnections/relation/creating', $creating );
		add_action( 'wpConnections/relation/created', $created );

		try {
			$this->assert_connection_error(
				310,
				'Connection relation identity mismatch: expected entity-hook-owner, got entity-hook-other.',
				function () use ( $relation ): void {
					$relation->createConnection(
						new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] )
					);
				}
			);
		} finally {
			remove_action( 'wpConnections/relation/creating', $creating );
			remove_action( 'wpConnections/relation/created', $created );
		}

		self::assertSame( 0, $created_calls );
		self::assertCount( 0, EntityValidationRecordingStorage::$created );
	}

	public function test_creating_hook_revalidates_changed_endpoints_without_double_resolving_unchanged_state(): void
	{
		$client = $this->recording_client( 'entity-hook-endpoints' );
		$resolver = new class() implements EntityResolverInterface {
			public array $calls = [];

			public function getSupportedEntityTypes(): array
			{
				return [ 'core04_hook_from', 'core04_hook_to' ];
			}

			public function resolve( int $entity_id, string $entity_type ): EntityResolution
			{
				$this->calls[] = $entity_type . ':' . $entity_id;

				if ( 99 === $entity_id ) {
					return EntityResolution::missing();
				}

				return EntityResolution::accepted();
			}
		};
		$client->registerEntityResolver( $resolver );
		$relation = $this->register_relation_on(
			$client,
			'entity-hook-endpoints',
			'core04_hook_from',
			'core04_hook_to',
			[ 'duplicatable' => true ]
		);

		$title_only = static function ( ConnectionQuery $query ): void {
			$query->title = 'Transformed title';
		};
		add_action( 'wpConnections/relation/creating', $title_only );
		try {
			$created = $relation->createConnection( new ConnectionQuery( 1, 2 ) );
		} finally {
			remove_action( 'wpConnections/relation/creating', $title_only );
		}

		self::assertSame( 'Transformed title', $created->title );
		self::assertSame( [ 'core04_hook_from:1', 'core04_hook_to:2' ], $resolver->calls );

		$change_endpoint = static function ( ConnectionQuery $query ): void {
			$query->from = 3;
		};
		add_action( 'wpConnections/relation/creating', $change_endpoint );
		try {
			$changed = $relation->createConnection( new ConnectionQuery( 1, 4 ) );
		} finally {
			remove_action( 'wpConnections/relation/creating', $change_endpoint );
		}

		self::assertSame( 3, $changed->from );
		self::assertSame(
			[
				'core04_hook_from:1',
				'core04_hook_to:2',
				'core04_hook_from:1',
				'core04_hook_to:4',
				'core04_hook_from:3',
				'core04_hook_to:4',
			],
			$resolver->calls
		);

		$reject_endpoint = static function ( ConnectionQuery $query ): void {
			$query->to = 99;
		};
		add_action( 'wpConnections/relation/creating', $reject_endpoint );
		try {
			$this->assert_connection_error(
				306,
				'Connection endpoint entity not found: to=99.',
				static function () use ( $relation ): void {
					$relation->createConnection( new ConnectionQuery( 5, 6 ) );
				}
			);
		} finally {
			remove_action( 'wpConnections/relation/creating', $reject_endpoint );
		}

		self::assertCount( 2, EntityValidationRecordingStorage::$created );
	}

	public function test_creating_hook_endpoint_changes_recheck_all_existing_invariants(): void
	{
		$closure_relation = $this->register_relation(
			'entity-hook-closure',
			'post',
			'post',
			[ 'duplicatable' => true ]
		);
		$make_closure = static function ( ConnectionQuery $query ): void {
			$query->to = $query->from;
		};
		add_action( 'wpConnections/relation/creating', $make_closure );
		try {
			$this->assert_connection_error(
				301,
				'Closurable not allowed by relation settings.',
				function () use ( $closure_relation ): void {
					$closure_relation->createConnection(
						new ConnectionQuery( $this->post_ids[0], $this->post_ids[1] )
					);
				}
			);
		} finally {
			remove_action( 'wpConnections/relation/creating', $make_closure );
		}

		$other_page = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$duplicate_relation = $this->register_relation( 'entity-hook-duplicate', 'page', 'post' );
		$duplicate_relation->createConnection(
			new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] )
		);
		$make_duplicate = function ( ConnectionQuery $query ): void {
			$query->from = $this->page_ids[0];
			$query->to   = $this->post_ids[0];
		};
		add_action( 'wpConnections/relation/creating', $make_duplicate );
		try {
			$this->assert_connection_error(
				303,
				'Duplicatable violation.',
				function () use ( $duplicate_relation, $other_page ): void {
					$duplicate_relation->createConnection(
						new ConnectionQuery( $other_page, $this->post_ids[1] )
					);
				}
			);
		} finally {
			remove_action( 'wpConnections/relation/creating', $make_duplicate );
		}

		$cardinality_relation = $this->register_relation(
			'entity-hook-cardinality',
			'page',
			'post',
			[
				'cardinality'  => '1-m',
				'duplicatable' => true,
			]
		);
		$cardinality_relation->createConnection(
			new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] )
		);
		$occupy_to = function ( ConnectionQuery $query ): void {
			$query->to = $this->post_ids[0];
		};
		add_action( 'wpConnections/relation/creating', $occupy_to );
		try {
			$this->assert_connection_error(
				302,
				'Cardinality violation.',
				function () use ( $cardinality_relation, $other_page ): void {
					$cardinality_relation->createConnection(
						new ConnectionQuery( $other_page, $this->post_ids[1] )
					);
				}
			);
		} finally {
			remove_action( 'wpConnections/relation/creating', $occupy_to );
		}

		self::assertCount( 0, $closure_relation->findConnections() );
		self::assertCount( 1, $duplicate_relation->findConnections() );
		self::assertCount( 1, $cardinality_relation->findConnections() );
	}

	public function test_rest_create_delegates_to_the_same_domain_validator(): void
	{
		$this->set_up_rest_server();
		$this->authenticate_as_administrator();

		try {
			$response = $this->dispatch_rest_request(
				'POST',
				$this->get_rest_route( '/relation/' . RELATION_0_NAME ),
				[
					'from' => $this->post_ids[0],
					'to'   => $this->post_ids[1],
				]
			);
			$data = $response->get_data();

			self::assertSame( 307, $data['code'] ?? null );
			self::assertSame(
				'Connection endpoint type mismatch: from expected page, got post.',
				$data['message'] ?? null
			);
			self::assertCount( 0, $this->client->getRelation( RELATION_0_NAME )->findConnections() );
		} finally {
			$this->tear_down_rest_server();
		}
	}

	public function test_rest_update_delegates_to_the_same_effective_state_validator(): void
	{
		$relation   = $this->client->getRelation( RELATION_0_NAME );
		$connection = $relation->createConnection(
			new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] )
		);
		$this->set_up_rest_server();
		$this->authenticate_as_administrator();

		try {
			$response = $this->dispatch_rest_request(
				'PATCH',
				$this->get_rest_route(
					'/relation/' . RELATION_0_NAME . '/' . $connection->id
				),
				[
					'from' => $this->post_ids[1],
					'to'   => $this->post_ids[0],
				]
			);
			$data = $response->get_data();

			self::assertSame( 307, $data['code'] ?? null );
			self::assertSame(
				'Connection endpoint type mismatch: from expected page, got post.',
				$data['message'] ?? null
			);
			$persisted = $this->find_connection( $relation, $connection->id );
			self::assertSame( $this->page_ids[0], $persisted->from );
			self::assertSame( $this->post_ids[0], $persisted->to );
		} finally {
			$this->tear_down_rest_server();
		}
	}

	public function test_rest_meta_update_validates_legacy_state_while_cleanup_skips_resolution(): void
	{
		$resolver = new class() implements EntityResolverInterface {
			public array $calls = [];

			public function getSupportedEntityTypes(): array
			{
				return [ 'core04_legacy_from', 'core04_legacy_to' ];
			}

			public function resolve( int $entity_id, string $entity_type ): EntityResolution
			{
				$this->calls[] = $entity_type . ':' . $entity_id;

				return EntityResolution::missing();
			}
		};
		$this->client->registerEntityResolver( $resolver );
		$relation = $this->register_relation(
			'entity-rest-legacy',
			'core04_legacy_from',
			'core04_legacy_to'
		);
		$legacy = new ConnectionQuery( 801, 802 );
		$legacy->set( 'relation', $relation->name );
		$legacy_id = $this->client->getStorage()->createConnection( $legacy );
		$meta = new MetaCollection();
		$meta->add( new Meta( 'legacy', 'original' ) );
		$this->client->getStorage()->addConnectionMeta( $legacy_id, $meta );

		$this->set_up_rest_server();
		$this->authenticate_as_administrator();
		$connection_route = $this->get_rest_route(
			'/relation/' . $relation->name . '/' . $legacy_id
		);

		try {
			$update_response = $this->dispatch_rest_request(
				'PATCH',
				$connection_route . '/meta',
				[ 'meta' => [ [ 'key' => 'legacy', 'value' => 'changed' ] ] ]
			);
			$update_data = $update_response->get_data();
			self::assertSame( 306, $update_data['code'] ?? null );
			self::assertSame(
				'Connection endpoint entity not found: from=801.',
				$update_data['message'] ?? null
			);
			self::assertSame( [ 'core04_legacy_from:801' ], $resolver->calls );
			$persisted = $this->find_connection( $relation, $legacy_id );
			self::assertSame( [ 'legacy' => [ 'original' ] ], $persisted->meta->toArray() );

			$meta_delete_response = $this->dispatch_rest_request(
				'DELETE',
				$connection_route . '/meta'
			);
			self::assertSame( [ 'deleted' => 1 ], $meta_delete_response->get_data() );
			self::assertSame( [ 'core04_legacy_from:801' ], $resolver->calls );
			self::assertSame(
				[],
				$this->find_connection( $relation, $legacy_id )->meta->toArray()
			);

			$delete_response = $this->dispatch_rest_request( 'DELETE', $connection_route );
			self::assertSame( [ 'deleted' => true ], $delete_response->get_data() );
			self::assertSame( [ 'core04_legacy_from:801' ], $resolver->calls );
			self::assertCount( 0, $relation->findConnections() );
		} finally {
			$this->tear_down_rest_server();
		}
	}

	public function test_deleted_post_cascade_cleans_legacy_row_without_endpoint_resolution(): void
	{
		global $wpdb;

		$resolver = new class() implements EntityResolverInterface {
			public array $calls = [];

			public function getSupportedEntityTypes(): array
			{
				return [ 'core04_cascade' ];
			}

			public function resolve( int $entity_id, string $entity_type ): EntityResolution
			{
				$this->calls[] = $entity_type . ':' . $entity_id;

				return EntityResolution::failed();
			}
		};
		$this->client->registerEntityResolver( $resolver );
		$relation = $this->register_relation(
			'entity-cascade-legacy',
			'core04_cascade',
			'core04_cascade'
		);
		$deleted_post_id = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$legacy = new ConnectionQuery( $deleted_post_id, 902 );
		$legacy->set( 'relation', $relation->name );
		$legacy_id = $this->client->getStorage()->createConnection( $legacy );
		$meta = new MetaCollection();
		$meta->add( new Meta( 'legacy', 'cascade' ) );
		$this->client->getStorage()->addConnectionMeta( $legacy_id, $meta );

		wp_delete_post( $deleted_post_id, true );

		self::assertSame( [], $resolver->calls );
		self::assertCount( 0, $relation->findConnections() );
		$storage = $this->client->getStorage();
		self::assertSame(
			'0',
			$wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . $wpdb->prefix . $storage->get_meta_table()
					. ' WHERE connection_id = %d',
					$legacy_id
				)
			)
		);
	}

	public function test_entity_failure_precedes_duplicate_and_cardinality(): void
	{
		$relation = $this->register_relation(
			'entity-before-existing-row',
			'page',
			'post',
			[ 'cardinality' => '1-1' ]
		);
		$relation->createConnection( new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] ) );
		set_post_type( $this->page_ids[0], 'post' );

		$this->assert_connection_error(
			307,
			'Connection endpoint type mismatch: from expected page, got post.',
			function () use ( $relation ): void {
				$relation->createConnection( new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] ) );
			}
		);
		self::assertCount( 1, $relation->findConnections() );
	}

	public function test_relation_sparse_update_materializes_and_validates_complete_state(): void
	{
		$relation   = $this->register_relation( 'entity-sparse-update', 'page', 'post' );
		$connection = $relation->createConnection(
			new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] )
		);
		$connection->title = 'Original';
		$connection->order = 9;
		$connection->meta->add( new Meta( 'marker', 'preserved' ) );
		$connection->update();

		$update = new ConnectionQuery();
		$update->set( 'id', $connection->id );
		$update->set( 'title', 'Changed' );
		self::assertTrue( $relation->updateConnection( $update ) );

		$persisted = $this->find_connection( $relation, $connection->id );
		self::assertSame( $this->page_ids[0], $persisted->from );
		self::assertSame( $this->post_ids[0], $persisted->to );
		self::assertSame( 9, $persisted->order );
		self::assertSame( 'Changed', $persisted->title );
		self::assertSame( [ 'marker' => [ 'preserved' ] ], $persisted->meta->toArray() );

		$changed_from = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$changed_to   = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$from_update  = new ConnectionQuery();
		$from_update->set( 'id', $connection->id );
		$from_update->set( 'from', $changed_from );
		self::assertTrue( $relation->updateConnection( $from_update ) );

		$to_update = new ConnectionQuery();
		$to_update->set( 'id', $connection->id );
		$to_update->set( 'to', $changed_to );
		self::assertTrue( $relation->updateConnection( $to_update ) );

		$persisted = $this->find_connection( $relation, $connection->id );
		self::assertSame( $changed_from, $persisted->from );
		self::assertSame( $changed_to, $persisted->to );
		self::assertSame( 9, $persisted->order );
		self::assertSame( 'Changed', $persisted->title );
		self::assertSame( [ 'marker' => [ 'preserved' ] ], $persisted->meta->toArray() );
	}

	public function test_relation_update_rejects_explicit_zero_and_preserves_persisted_state(): void
	{
		$relation   = $this->register_relation( 'entity-zero-update', 'page', 'post' );
		$connection = $relation->createConnection(
			new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] )
		);
		$update = new ConnectionQuery();
		$update->set( 'id', $connection->id );
		$update->set( 'from', 0 );

		$this->assert_connection_error(
			305,
			'Invalid connection endpoint ID: from=0.',
			static function () use ( $relation, $update ): void {
				$relation->updateConnection( $update );
			}
		);

		$persisted = $this->find_connection( $relation, $connection->id );
		self::assertSame( $this->page_ids[0], $persisted->from );
		self::assertSame( $this->post_ids[0], $persisted->to );
	}

	public function test_direct_zero_writes_are_supplied_invalid_and_do_not_persist(): void
	{
		$relation = $this->register_relation( 'entity-direct-zero', 'page', 'post' );
		$create   = new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] );
		$create->set( 'title', 'Original' );
		$connection = $relation->createConnection( $create );

		foreach ( [ 'from', 'to' ] as $field ) {
			$update           = new ConnectionQuery();
			$update->{$field} = 0;
			$update->set( 'id', $connection->id );
			$update->set( 'title', 'Must not persist' );

			self::assertTrue( $update->isProvided( $field ) );
			$this->assert_connection_error(
				305,
				"Invalid connection endpoint ID: {$field}=0.",
				static function () use ( $relation, $update ): void {
					$relation->updateConnection( $update );
				}
			);
		}

		$persisted = $this->find_connection( $relation, $connection->id );
		self::assertSame( $this->page_ids[0], $persisted->from );
		self::assertSame( $this->post_ids[0], $persisted->to );
		self::assertSame( 'Original', $persisted->title );
	}

	public function test_relation_update_requires_identity_before_loading_effective_state(): void
	{
		$relation   = $this->register_relation( 'entity-update-id', 'page', 'post' );
		$connection = $relation->createConnection(
			new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] )
		);
		$update     = new ConnectionQuery();
		$update->set( 'title', 'must not select an arbitrary row' );

		$this->assert_connection_error(
			304,
			'Cannot update uninitialized connection',
			static function () use ( $relation, $update ): void {
				$relation->updateConnection( $update );
			}
		);

		$persisted = $this->find_connection( $relation, $connection->id );
		self::assertNotSame( 'must not select an arbitrary row', $persisted->title );
	}

	public function test_both_update_entrypoints_report_missing_positive_id_without_writes(): void
	{
		$client   = $this->recording_client( 'entity-update-missing' );
		$relation = $this->register_relation_on( $client, 'entity-update-missing', 'page', 'post' );
		$query    = new ConnectionQuery();
		$query->set( 'id', 999 );
		$query->set( 'title', 'Must not persist' );

		$this->assert_connection_not_found(
			static function () use ( $relation, $query ): void {
				$relation->updateConnection( $query );
			}
		);

		$aggregate_query = new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] );
		$aggregate_query->set( 'id', 999 );
		$aggregate_query->set( 'relation', $relation->name );
		$aggregate_query->set( 'title', 'Must not persist' );
		$aggregate_query->set( 'order', 0 );
		$aggregate = new Connection( $aggregate_query );
		$aggregate->setClient( $client );

		$this->assert_connection_not_found(
			static function () use ( $aggregate ): void {
				$aggregate->update();
			}
		);

		self::assertCount( 0, EntityValidationRecordingStorage::$updated );
		self::assertCount( 0, EntityValidationRecordingStorage::$removed_meta );
		self::assertCount( 0, EntityValidationRecordingStorage::$added_meta );
	}

	public function test_recording_adapter_preserves_changed_and_no_op_update_signatures(): void
	{
		$client   = $this->recording_client( 'entity-update-results' );
		$relation = $this->register_relation_on( $client, 'entity-update-results', 'page', 'post' );
		$stored_query = new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] );
		$stored_query->set( 'id', 701 );
		$stored_query->set( 'relation', $relation->name );
		$stored_query->set( 'title', 'Stored title' );
		$stored_query->set( 'order', 4 );
		$stored = new Connection( $stored_query );
		EntityValidationRecordingStorage::$found = new ConnectionCollection( [ $stored ] );

		$changed = new ConnectionQuery();
		$changed->set( 'id', 701 );
		$changed->set( 'title', 'Changed title' );
		self::assertTrue( $relation->updateConnection( $changed ) );

		EntityValidationRecordingStorage::$update_result = false;
		$no_op = new ConnectionQuery();
		$no_op->set( 'id', 701 );
		self::assertFalse( $relation->updateConnection( $no_op ) );

		$stored->setClient( $client );
		$stored->meta->add( new Meta( 'marker', 'preserved' ) );
		self::assertNull( $stored->update() );

		self::assertCount( 3, EntityValidationRecordingStorage::$updated );
		self::assertCount( 1, EntityValidationRecordingStorage::$removed_meta );
		self::assertCount( 1, EntityValidationRecordingStorage::$added_meta );
	}

	public function test_connection_update_rejects_invalid_full_state_without_mutating_storage(): void
	{
		$relation   = $this->register_relation( 'entity-aggregate-update', 'page', 'post' );
		$connection = $relation->createConnection(
			new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] )
		);
		$connection->meta->add( new Meta( 'marker', 'preserved' ) );
		$connection->order = 7;
		$connection->update();
		$connection->from  = $this->post_ids[1];
		$connection->title = 'Rejected';

		$this->assert_connection_error(
			307,
			'Connection endpoint type mismatch: from expected page, got post.',
			static function () use ( $connection ): void {
				$connection->update();
			}
		);

		$persisted = $this->find_connection( $relation, $connection->id );
		self::assertSame( $this->page_ids[0], $persisted->from );
		self::assertNotSame( 'Rejected', $persisted->title );
		self::assertSame( 7, $persisted->order );
		self::assertSame( [ 'marker' => [ 'preserved' ] ], $persisted->meta->toArray() );

		$wrong_to         = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$connection->from = $this->page_ids[0];
		$connection->to   = $wrong_to;
		$this->assert_connection_error(
			307,
			'Connection endpoint type mismatch: to expected post, got page.',
			static function () use ( $connection ): void {
				$connection->update();
			}
		);

		$persisted = $this->find_connection( $relation, $connection->id );
		self::assertSame( $this->post_ids[0], $persisted->to );
		self::assertSame( 7, $persisted->order );
		self::assertSame( [ 'marker' => [ 'preserved' ] ], $persisted->meta->toArray() );
	}

	public function test_connection_and_relation_updates_reject_relation_identity_changes_first(): void
	{
		$relation = $this->register_relation( 'entity-owner-a', 'page', 'post' );
		$other    = $this->register_relation( 'entity-owner-b', 'page', 'post' );
		$item     = $relation->createConnection(
			new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] )
		);
		$item->relation = $other->name;
		$item->from     = -1;

		$this->assert_connection_error(
			310,
			'Connection relation identity mismatch: expected entity-owner-a, got entity-owner-b.',
			static function () use ( $item ): void {
				$item->update();
			}
		);

		$query = new ConnectionQuery( -1, -2 );
		$query->set( 'id', $item->id );
		$this->assert_connection_error(
			310,
			'Connection relation identity mismatch: expected entity-owner-a, got entity-owner-b.',
			static function () use ( $other, $query ): void {
				$other->updateConnection( $query );
			}
		);
	}

	public function test_legacy_invalid_rows_remain_readable_deletable_and_not_updatable(): void
	{
		$relation = $this->register_relation( 'entity-legacy-row', 'page', 'post' );
		$legacy   = new ConnectionQuery( 999999991, 999999992 );
		$legacy->set( 'relation', $relation->name );
		$legacy_id = $this->client->getStorage()->createConnection( $legacy );
		$meta      = new MetaCollection();
		$meta->add( new Meta( 'legacy', 'cleanup' ) );
		$this->client->getStorage()->addConnectionMeta( $legacy_id, $meta );

		$read = $this->find_connection( $relation, $legacy_id );
		self::assertSame( 999999991, $read->from );
		self::assertSame( $this->client, $read->getClient() );
		$read->title = 'aggregate must not persist';
		$this->assert_connection_error(
			306,
			'Connection endpoint entity not found: from=999999991.',
			static function () use ( $read ): void {
				$read->update();
			}
		);

		$persisted = $this->find_connection( $relation, $legacy_id );
		self::assertNotSame( 'aggregate must not persist', $persisted->title );
		self::assertSame( [ 'legacy' => [ 'cleanup' ] ], $persisted->meta->toArray() );

		$update = new ConnectionQuery();
		$update->set( 'id', $legacy_id );
		$update->set( 'title', 'must not persist' );
		$this->assert_connection_error(
			306,
			'Connection endpoint entity not found: from=999999991.',
			static function () use ( $relation, $update ): void {
				$relation->updateConnection( $update );
			}
		);

		$meta_delete = new ConnectionQuery();
		$meta_delete->set( 'id', $legacy_id );
		self::assertSame( 1, $relation->removeConnectionMeta( $meta_delete ) );

		$delete = new ConnectionQuery();
		$delete->set( 'id', $legacy_id );
		self::assertSame( 1, $relation->detachConnections( $delete ) );
		self::assertCount( 0, $relation->findConnections() );
	}

	public function test_non_post_resolver_supports_structured_outcomes(): void
	{
		self::assertTrue( interface_exists( EntityResolverInterface::class ) );
		self::assertTrue( class_exists( EntityResolution::class ) );

		$relation = $this->register_relation( 'entity-custom', 'core04_user', 'core04_term' );
		$this->client->registerEntityResolver(
			$this->resolver(
				[ 'core04_user', 'core04_term' ],
				[
					'core04_user:10' => EntityResolution::accepted(),
					'core04_term:20' => EntityResolution::accepted(),
					'core04_user:11' => EntityResolution::missing(),
					'core04_user:12' => EntityResolution::wrongType( 'other' ),
					'core04_user:13' => EntityResolution::failed(),
				]
			)
		);

		$created = $relation->createConnection( new ConnectionQuery( 10, 20 ) );
		self::assertGreaterThan( 0, $created->id );
		$this->assert_connection_error(
			306,
			'Connection endpoint entity not found: from=11.',
			static function () use ( $relation ): void {
				$relation->createConnection( new ConnectionQuery( 11, 20 ) );
			}
		);
		$this->assert_connection_error(
			307,
			'Connection endpoint type mismatch: from expected core04_user, got other.',
			static function () use ( $relation ): void {
				$relation->createConnection( new ConnectionQuery( 12, 20 ) );
			}
		);
		$this->assert_connection_error(
			309,
			'Connection endpoint resolver failed: from=core04_user#13.',
			static function () use ( $relation ): void {
				$relation->createConnection( new ConnectionQuery( 13, 20 ) );
			}
		);
		self::assertCount( 1, $relation->findConnections() );
	}

	public function test_unsupported_and_throwing_resolvers_fail_without_persistence(): void
	{
		self::assertTrue( interface_exists( EntityResolverInterface::class ) );
		$unsupported = $this->register_relation( 'entity-unsupported', 'core04_none', 'core04_none' );
		$this->assert_connection_error(
			308,
			'Unsupported connection endpoint type: from=core04_none.',
			static function () use ( $unsupported ): void {
				$unsupported->createConnection( new ConnectionQuery( 1, 2 ) );
			}
		);
		self::assertCount( 0, $unsupported->findConnections() );

		$client = $this->recording_client( 'entity-resolver-throws' );
		$client->registerEntityResolver(
			$this->resolver( [ 'core04_throw' ], [], true )
		);
		$relation = $this->register_relation_on( $client, 'entity-throw', 'core04_throw', 'core04_throw' );
		$exception = $this->capture_exception(
			static function () use ( $relation ): void {
				$relation->createConnection( new ConnectionQuery( 1, 2 ) );
			}
		);
		self::assertSame( ConnectionEndpointResolverFail::class, get_class( $exception ) );
		self::assertSame( 309, $exception->getCode() );
		self::assertSame(
			'Connection endpoint resolver failed: from=core04_throw#1.',
			$exception->getMessage()
		);
		self::assertInstanceOf( \RuntimeException::class, $exception->getPrevious() );
		self::assertSame( 'Resolver detail must remain internal.', $exception->getPrevious()->getMessage() );
		self::assertCount( 0, EntityValidationRecordingStorage::$created );
	}

	public function test_resolver_registry_rejects_duplicate_builtin_and_late_claims(): void
	{
		self::assertTrue( interface_exists( EntityResolverInterface::class ) );
		$client = $this->recording_client( 'entity-registry' );
		$client->registerEntityResolver( $this->resolver( [ 'core04_external' ] ) );

		$this->assert_client_error(
			'Duplicate entity resolver type: core04_external.',
			function () use ( $client ): void {
				$client->registerEntityResolver( $this->resolver( [ 'core04_external' ] ) );
			}
		);
		$this->assert_client_error(
			'Entity resolver cannot claim WordPress post type: page.',
			function () use ( $client ): void {
				$client->registerEntityResolver( $this->resolver( [ 'page' ] ) );
			}
		);

		$relation = $this->register_relation_on( $client, 'entity-registry-use', 'core04_external', 'core04_external' );
		$relation->createConnection( new ConnectionQuery( 1, 2 ) );
		$this->assert_client_error(
			'Entity resolvers must be registered before the first connection mutation.',
			function () use ( $client ): void {
				$client->registerEntityResolver( $this->resolver( [ 'core04_late' ] ) );
			}
		);
	}

	public function test_resolver_registry_is_client_scoped(): void
	{
		self::assertTrue( interface_exists( EntityResolverInterface::class ) );
		$first = $this->recording_client( 'entity-client-first' );
		$first->registerEntityResolver( $this->resolver( [ 'core04_scoped' ] ) );
		$first_relation = $this->register_relation_on( $first, 'entity-scoped', 'core04_scoped', 'core04_scoped' );
		self::assertSame( 701, $first_relation->createConnection( new ConnectionQuery( 1, 2 ) )->id );

		EntityValidationRecordingStorage::reset();
		$second          = $this->recording_client( 'entity-client-second' );
		$second_relation = $this->register_relation_on( $second, 'entity-scoped', 'core04_scoped', 'core04_scoped' );
		$this->assert_connection_error(
			308,
			'Unsupported connection endpoint type: from=core04_scoped.',
			static function () use ( $second_relation ): void {
				$second_relation->createConnection( new ConnectionQuery( 1, 2 ) );
			}
		);
		self::assertCount( 0, EntityValidationRecordingStorage::$created );
	}

	public function test_domain_owns_storage_payload_id_and_client_hydration(): void
	{
		$client   = $this->recording_client( 'entity-storage-boundary' );
		$relation = $this->register_relation_on( $client, 'entity-storage', 'page', 'post' );
		$this->assert_connection_error(
			307,
			'Connection endpoint type mismatch: from expected page, got post.',
			function () use ( $relation ): void {
				$relation->createConnection( new ConnectionQuery( $this->post_ids[0], $this->post_ids[1] ) );
			}
		);
		self::assertCount( 0, EntityValidationRecordingStorage::$created );

		$query    = new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] );
		$created  = $relation->createConnection( $query );

		self::assertSame( 701, $created->id );
		self::assertSame( 701, $query->id );
		self::assertSame( $client, $created->getClient() );
		self::assertCount( 1, EntityValidationRecordingStorage::$created );
		self::assertSame( 0, EntityValidationRecordingStorage::$created[0]->id );

		$stored_query = new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] );
		$stored_query->set( 'id', 701 );
		$stored_query->set( 'relation', $relation->name );
		$stored_query->set( 'title', 'Stored title' );
		$stored_query->set( 'order', 4 );
		EntityValidationRecordingStorage::$found = new ConnectionCollection(
			[ new Connection( $stored_query ) ]
		);

		$hydrated = $this->find_connection( $relation, 701 );
		self::assertSame( $client, $hydrated->getClient() );

		$rejected = new ConnectionQuery();
		$rejected->set( 'id', 701 );
		$rejected->set( 'from', $this->post_ids[1] );
		$this->assert_connection_error(
			307,
			'Connection endpoint type mismatch: from expected page, got post.',
			static function () use ( $relation, $rejected ): void {
				$relation->updateConnection( $rejected );
			}
		);
		self::assertCount( 0, EntityValidationRecordingStorage::$updated );

		$update = new ConnectionQuery();
		$update->set( 'id', 701 );
		$update->set( 'title', 'Updated title' );
		self::assertTrue( $relation->updateConnection( $update ) );
		self::assertCount( 1, EntityValidationRecordingStorage::$updated );
		self::assertSame( Connection::class, get_class( EntityValidationRecordingStorage::$updated[0] ) );
		self::assertSame( $this->page_ids[0], EntityValidationRecordingStorage::$updated[0]->from );
		self::assertSame( $this->post_ids[0], EntityValidationRecordingStorage::$updated[0]->to );
		self::assertSame( 4, EntityValidationRecordingStorage::$updated[0]->order );
		self::assertSame( 'Updated title', EntityValidationRecordingStorage::$updated[0]->title );
	}

	private function register_relation(
		string $name,
		string $from,
		string $to,
		array $overrides = []
	): \iTRON\wpConnections\Relation {
		return $this->register_relation_on( $this->client, $name, $from, $to, $overrides );
	}

	private function register_relation_on(
		Client $client,
		string $name,
		string $from,
		string $to,
		array $overrides = []
	): \iTRON\wpConnections\Relation {
		$query  = new RelationQuery();
		$fields = array_merge(
			[
				'name'         => $name,
				'from'         => $from,
				'to'           => $to,
				'cardinality'  => 'm-m',
				'duplicatable' => false,
				'closurable'   => false,
			],
			$overrides
		);

		foreach ( $fields as $field => $value ) {
			$query->set( $field, $value );
		}

		return $client->registerRelation( $query );
	}

	private function recording_client( string $name ): Client
	{
		EntityValidationRecordingStorage::reset();
		add_filter(
			'wpConnections/factory/getStorage/class',
			[ EntityValidationRecordingStorage::class, 'use_for_test' ],
			10,
			2
		);

		$client = new Client( $name );
		$this->additional_clients[] = $client;

		return $client;
	}

	private function resolver( array $types, array $results = [], bool $throws = false )
	{
		return new class( $types, $results, $throws ) implements EntityResolverInterface {
			private array $types;
			private array $results;
			private bool $throws;

			public function __construct( array $types, array $results, bool $throws )
			{
				$this->types   = $types;
				$this->results = $results;
				$this->throws  = $throws;
			}

			public function getSupportedEntityTypes(): array
			{
				return $this->types;
			}

			public function resolve( int $entity_id, string $entity_type ): EntityResolution
			{
				if ( $this->throws ) {
					throw new \RuntimeException( 'Resolver detail must remain internal.' );
				}

				return $this->results[ $entity_type . ':' . $entity_id ] ?? EntityResolution::accepted();
			}
		};
	}

	private function find_connection(
		\iTRON\wpConnections\Relation $relation,
		int $connection_id
	): Connection {
		$query = new ConnectionQuery();
		$query->set( 'id', $connection_id );

		return $relation->findConnections( $query )->first();
	}

	private function assert_connection_error( int $code, string $message, callable $operation ): void
	{
		$exception = $this->capture_exception( $operation );
		$exact_classes = [
			305 => ConnectionEndpointInvalid::class,
			306 => ConnectionEndpointNotFound::class,
			307 => ConnectionEndpointTypeMismatch::class,
			308 => ConnectionEndpointTypeUnsupported::class,
			309 => ConnectionEndpointResolverFail::class,
			310 => ConnectionRelationMismatch::class,
		];

		self::assertInstanceOf( ConnectionWrongData::class, $exception );
		if ( isset( $exact_classes[ $code ] ) ) {
			self::assertSame( $exact_classes[ $code ], get_class( $exception ) );
		}
		self::assertSame( $code, $exception->getCode() );
		self::assertSame( $message, $exception->getMessage() );
	}

	private function assert_connection_not_found( callable $operation ): void
	{
		$exception = $this->capture_exception( $operation );

		self::assertSame( ConnectionNotFound::class, get_class( $exception ) );
		self::assertSame( 2, $exception->getCode() );
		self::assertSame( 'Connection not found.', $exception->getMessage() );
	}

	private function assert_client_error( string $message, callable $operation ): void
	{
		$exception = $this->capture_exception( $operation );

		self::assertInstanceOf( ClientRegisterFail::class, $exception );
		self::assertSame( $message, $exception->getMessage() );
	}

	private function capture_exception( callable $operation ): \Throwable
	{
		try {
			$operation();
		} catch ( \Throwable $exception ) {
			return $exception;
		}

		self::fail( 'Expected operation to throw an exception.' );
	}
}
