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
use iTRON\wpConnections\Exceptions\ConnectionWrongData;
use iTRON\wpConnections\Meta;
use iTRON\wpConnections\MetaCollection;
use iTRON\wpConnections\Query\Connection as ConnectionQuery;
use iTRON\wpConnections\Query\MetaCollection as MetaQueryCollection;
use iTRON\wpConnections\Query\Relation as RelationQuery;

class EntityValidationRecordingStorage extends Storage
{
	public static array $created = [];
	public static array $updated = [];
	public static ?ConnectionCollection $found = null;
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
		self::$found   = null;
	}

	public function createConnection( ConnectionQuery $connection_query ): int
	{
		self::$created[] = clone $connection_query;

		return 700 + count( self::$created );
	}

	public function updateConnection( AbstractConnection $connection ): bool
	{
		self::$updated[] = clone $connection;

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
		int $from = null,
		int $to = null,
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
	}

	public function removeConnectionMeta( int $object_id, MetaQueryCollection $meta_query )
	{
		return 0;
	}
}

class EntityValidationTest extends WPConnectionsTestCase
{
	public function tear_down()
	{
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
			306,
			'Connection endpoint entity not found: from=999999999.',
			static function () use ( $relation ): void {
				$relation->createConnection( new ConnectionQuery( 999999999, 999999998 ) );
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

	public function test_connection_update_rejects_invalid_full_state_without_mutating_storage(): void
	{
		$relation   = $this->register_relation( 'entity-aggregate-update', 'page', 'post' );
		$connection = $relation->createConnection(
			new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] )
		);
		$connection->meta->add( new Meta( 'marker', 'preserved' ) );
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

		$client = $this->recording_client( 'entity-resolver-throws' );
		$client->registerEntityResolver(
			$this->resolver( [ 'core04_throw' ], [], true )
		);
		$relation = $this->register_relation_on( $client, 'entity-throw', 'core04_throw', 'core04_throw' );
		$this->assert_connection_error(
			309,
			'Connection endpoint resolver failed: from=core04_throw#1.',
			static function () use ( $relation ): void {
				$relation->createConnection( new ConnectionQuery( 1, 2 ) );
			}
		);
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

		return new Client( $name );
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

		self::assertInstanceOf( ConnectionWrongData::class, $exception );
		self::assertSame( $code, $exception->getCode() );
		self::assertSame( $message, $exception->getMessage() );
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
