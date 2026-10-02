<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Abstracts\Connection as AbstractConnection;
use iTRON\wpConnections\Abstracts\Storage;
use iTRON\wpConnections\AtomicStorageInterface;
use iTRON\wpConnections\Client;
use iTRON\wpConnections\ClientRestApi;
use iTRON\wpConnections\Connection;
use iTRON\wpConnections\ConnectionCollection;
use iTRON\wpConnections\Exceptions\ClientRegisterFail;
use iTRON\wpConnections\Exceptions\ConnectionNotFound;
use iTRON\wpConnections\MetaCollection;
use iTRON\wpConnections\Query\Connection as ConnectionQuery;
use iTRON\wpConnections\Query\MetaCollection as MetaQueryCollection;
use iTRON\wpConnections\Query\Relation as RelationQuery;
use iTRON\wpConnections\TransactionContext;
use Psr\Log\AbstractLogger;

/** Portable eight-operation adapter with an atomic snapshot, not a SQL lock model. */
class ExtensionCompatibilityStorage extends Storage implements AtomicStorageInterface
{
	public static array $instances = [];
	public array $calls = [];
	public array $rows = [];
	public $before_atomic = null;
	private Client $client;
	private int $next_id = 1;

	public function __construct( Client $client )
	{
		$this->client = $client;
		self::$instances[] = $this;
	}

	public function runAtomically( callable $operation, TransactionContext $context )
	{
		if ( null !== $this->before_atomic ) {
			$callback = $this->before_atomic;
			$this->before_atomic = null;
			$callback();
		}
		$snapshot = $this->copy_rows();
		$this->calls[] = 'atomic:start';
		try {
			$result = $operation();
			$this->calls[] = 'atomic:commit';
			return $result;
		} catch ( \Throwable $failure ) {
			$this->rows = $snapshot;
			$this->calls[] = 'atomic:rollback';
			throw $failure;
		}
	}

	public function createConnection( ConnectionQuery $connection_query ): int
	{
		$id = $this->next_id++;
		$connection_query->set( 'id', $id );
		$row = new Connection( $connection_query );
		$row->setClient( $this->client );
		$this->rows[ $id ] = $row;
		$this->calls[] = 'create';
		return $id;
	}

	public function updateConnection( AbstractConnection $connection ): bool
	{
		$id = (int) $connection->id;
		$this->calls[] = 'update';
		if ( ! isset( $this->rows[ $id ] ) || $this->rows[ $id ]->relation !== $connection->relation ) {
			throw new ConnectionNotFound();
		}
		$changed = false;
		foreach ( [ 'from', 'to', 'title', 'order' ] as $field ) {
			if ( $this->rows[ $id ]->{$field} !== $connection->{$field} ) {
				$this->rows[ $id ]->{$field} = $connection->{$field};
				$changed = true;
			}
		}
		return $changed;
	}

	public function deleteSpecificConnections( $connection_ids ): int
	{
		$this->calls[] = 'delete:id';
		$deleted = 0;
		foreach ( (array) $connection_ids as $id ) {
			if ( isset( $this->rows[ $id ] ) ) {
				unset( $this->rows[ $id ] );
				++$deleted;
			}
		}
		return $deleted;
	}

	public function deleteByObjectID( $object_ids, string $relation = '', bool $only_from = false, bool $only_to = false ): int
	{
		$this->calls[] = 'delete:object';
		$ids = (array) $object_ids;
		$matches = [];
		foreach ( $this->rows as $id => $row ) {
			if ( '' !== $relation && $row->relation !== $relation ) {
				continue;
			}
			$from = in_array( $row->from, $ids, true );
			$to = in_array( $row->to, $ids, true );
			if ( ( $only_from && $from ) || ( $only_to && $to ) || ( ! $only_from && ! $only_to && ( $from || $to ) ) ) {
				$matches[] = $id;
			}
		}
		return $this->deleteSpecificConnections( $matches );
	}

	public function deleteDirectedConnections( ?int $from = null, ?int $to = null, string $relation = '' ): int
	{
		$this->calls[] = 'delete:directed';
		$matches = [];
		foreach ( $this->rows as $id => $row ) {
			if ( ( null === $from || $from === $row->from ) && ( null === $to || $to === $row->to ) && ( '' === $relation || $relation === $row->relation ) ) {
				$matches[] = $id;
			}
		}
		return $this->deleteSpecificConnections( $matches );
	}

	public function findConnections( ConnectionQuery $params ): ConnectionCollection
	{
		$this->calls[] = 'find';
		$matches = [];
		foreach ( $this->rows as $row ) {
			if ( $params->get( 'id' ) && $params->get( 'id' ) !== $row->id ) {
				continue;
			}
			if ( $params->get( 'relation' ) && $params->get( 'relation' ) !== $row->relation ) {
				continue;
			}
			if ( $params->get( 'from' ) && $params->get( 'from' ) !== $row->from ) {
				continue;
			}
			if ( $params->get( 'to' ) && $params->get( 'to' ) !== $row->to ) {
				continue;
			}
			$matches[] = clone $row;
		}
		return new ConnectionCollection( $matches );
	}

	public function addConnectionMeta( int $object_id, MetaCollection $meta_collection ): void
	{
		$this->calls[] = 'meta:add';
		if ( ! isset( $this->rows[ $object_id ] ) ) {
			throw new ConnectionNotFound();
		}
		foreach ( $meta_collection->getIterator() as $meta ) {
			$this->rows[ $object_id ]->meta->add( clone $meta );
		}
	}

	public function removeConnectionMeta( int $object_id, MetaQueryCollection $meta_query )
	{
		$this->calls[] = 'meta:remove';
		if ( ! isset( $this->rows[ $object_id ] ) ) {
			throw new ConnectionNotFound();
		}
		$count = $this->rows[ $object_id ]->meta->count();
		$this->rows[ $object_id ]->meta = new MetaCollection();
		return $count;
	}

	private function copy_rows(): array
	{
		$copy = [];
		foreach ( $this->rows as $id => $row ) {
			$copy[ $id ] = clone $row;
		}
		return $copy;
	}
}

class ExtensionCompatibilityLogger extends AbstractLogger
{
	public static array $instances = [];
	public Client $client;

	public function __construct( Client $client )
	{
		$this->client = $client;
		self::$instances[] = $this;
	}

	public function log( $level, $message, array $context = [] ): void
	{
	}
}

class ExtensionCompatibilityRestApi extends ClientRestApi
{
	public static array $instances = [];

	public function __construct( Client $client )
	{
		parent::__construct( $client );
		self::$instances[] = $this;
	}
}

class ExtensionCompatibilityThrowingStorage extends ExtensionCompatibilityStorage
{
	public static ?\Throwable $failure = null;

	public function __construct( Client $client )
	{
		throw self::$failure;
	}
}

class ExtensionCompatibilityThrowingLogger extends ExtensionCompatibilityLogger
{
	public static ?\Throwable $failure = null;

	public function __construct( Client $client )
	{
		throw self::$failure;
	}
}

class ExtensionCompatibilityThrowingRestApi extends ExtensionCompatibilityRestApi
{
	public static ?\Throwable $failure = null;

	public function __construct( Client $client )
	{
		throw self::$failure;
	}
}

abstract class ExtensionCompatibilityAbstractRestApi extends ClientRestApi
{
}

class ExtensionCompatibilityIncompatible
{
	public static int $constructions = 0;

	public function __construct( Client $client )
	{
		++self::$constructions;
	}
}

class ExtensionCompatibilityTest extends \WP_UnitTestCase
{
	private array $clients = [];
	private array $filters = [];
	private int $next_client = 0;

	public function set_up()
	{
		parent::set_up();
		$this->clients = [];
		$this->filters = [];
		$this->next_client = 0;
		ExtensionCompatibilityStorage::$instances = [];
		ExtensionCompatibilityLogger::$instances = [];
		ExtensionCompatibilityRestApi::$instances = [];
		foreach ( [
			'getStorage' => ExtensionCompatibilityStorage::class,
			'getLogger' => ExtensionCompatibilityLogger::class,
			'getRestApi' => ExtensionCompatibilityRestApi::class,
		] as $factory => $class ) {
			$hook = "wpConnections/factory/{$factory}/class";
			$filter = static function ( $default, $client ) use ( $class ): string {
				return $class;
			};
			add_filter( $hook, $filter, 10, 2 );
			$this->filters[] = [ $hook, $filter ];
		}
	}

	public function tear_down()
	{
		foreach ( $this->filters as [ $hook, $filter ] ) {
			remove_filter( $hook, $filter, 10 );
		}
		foreach ( $this->clients as $client ) {
			$client->dispose();
		}
		\iTRON\wpConnections\Internal\DeletedPostRepairRuntime::instance()->resetForTests();
		parent::tear_down();
	}

	public function test_factory_filters_receive_defaults_and_exact_client_once(): void
	{
		$seen = [];
		$defaults = [
			'getStorage' => \iTRON\wpConnections\WPStorage::class,
			'getLogger' => \iTRON\wpConnections\Logger::class,
			'getRestApi' => ClientRestApi::class,
		];
		foreach ( $defaults as $factory => $default ) {
			$hook = "wpConnections/factory/{$factory}/class";
			$probe = static function ( $class, $client ) use ( &$seen, $factory ) {
				$seen[ $factory ][] = [ $class, $client ];
				return $class;
			};
			add_filter( $hook, $probe, 5, 2 );
			$probes[] = [ $hook, $probe ];
		}
		try {
			$client = $this->new_client();
		} finally {
			foreach ( $probes as [ $hook, $probe ] ) {
				remove_filter( $hook, $probe, 5 );
			}
		}
		foreach ( $defaults as $factory => $default ) {
			self::assertSame( [ [ $default, $client ] ], $seen[ $factory ] );
		}
		self::assertSame( ExtensionCompatibilityStorage::$instances[0], $client->getStorage() );
		self::assertSame( ExtensionCompatibilityLogger::$instances[0], $client->getLogger() );
		self::assertCount( 1, ExtensionCompatibilityRestApi::$instances );
		self::assertSame( $client, ExtensionCompatibilityLogger::$instances[0]->client );
	}

	public function test_client_capability_and_create_lifecycle_payloads_are_stable(): void
	{
		$name = 'extension-contract-1';
		$timeline = [];
		$capability = static function ( $default ) use ( &$timeline ): string {
			$timeline[] = [ 'capability', $default ];
			return 'edit_posts';
		};
		$global_init = static function ( $client ) use ( &$timeline ): void {
			$timeline[] = [ 'global-init', $client ];
		};
		$client_init = static function ( $client ) use ( &$timeline ): void {
			$timeline[] = [ 'client-init', $client ];
		};
		add_filter( "wpConnections/client/{$name}/clientDefaultCapabilities", $capability );
		add_action( 'wpConnections/client/inited', $global_init, 10, 1 );
		add_action( "wpConnections/client/{$name}/inited", $client_init, 10, 1 );
		try {
			$client = $this->new_client();
		} finally {
			remove_filter( "wpConnections/client/{$name}/clientDefaultCapabilities", $capability );
			remove_action( 'wpConnections/client/inited', $global_init, 10 );
			remove_action( "wpConnections/client/{$name}/inited", $client_init, 10 );
		}
		self::assertSame( [ [ 'capability', '' ], [ 'global-init', $client ], [ 'client-init', $client ] ], $timeline );
		self::assertSame( 'edit_posts', $client->capabilities->create );

		$relation = $this->register_post_relation( $client );
		$from = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$to = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$query = new ConnectionQuery( $from, $to );
		$creating = static function ( $argument ) use ( &$timeline ): void {
			$timeline[] = [ 'creating', $argument ];
		};
		$created = static function ( $argument ) use ( &$timeline ): void {
			$timeline[] = [ 'created', $argument ];
		};
		add_action( 'wpConnections/relation/creating', $creating, 10, 1 );
		add_action( 'wpConnections/relation/created', $created, 10, 1 );
		try {
			$connection = $relation->createConnection( $query );
		} finally {
			remove_action( 'wpConnections/relation/creating', $creating, 10 );
			remove_action( 'wpConnections/relation/created', $created, 10 );
		}
		self::assertSame( [ [ 'creating', $query ], [ 'created', $connection ] ], array_slice( $timeline, 3 ) );
		self::assertSame( $client, $connection->getClient() );
		self::assertSame( $connection->id, $client->getStorage()->rows[ $connection->id ]->id );
	}

	public function test_invalid_factory_replacements_fail_with_attributable_client_register_error(): void
	{
		$factories = [
			'getStorage' => [ Storage::class, ExtensionCompatibilityThrowingStorage::class ],
			'getLogger' => [ AbstractLogger::class, ExtensionCompatibilityThrowingLogger::class ],
			'getRestApi' => [ ExtensionCompatibilityAbstractRestApi::class, ExtensionCompatibilityThrowingRestApi::class ],
		];
		ExtensionCompatibilityIncompatible::$constructions = 0;
		foreach ( $factories as $factory => [ $abstract, $throwing ] ) {
			$hook = "wpConnections/factory/{$factory}/class";
			foreach ( [ null, 'MissingExtensionClass', ExtensionCompatibilityIncompatible::class, $abstract ] as $replacement ) {
				$filter = static function () use ( $replacement ) {
					return $replacement;
				};
				add_filter( $hook, $filter, 20 );
				try {
					new Client( 'extension-invalid-' . ++$this->next_client );
					self::fail( 'Expected ClientRegisterFail.' );
				} catch ( ClientRegisterFail $failure ) {
					self::assertSame( 4, $failure->getCode() );
					self::assertStringContainsString( $hook, $failure->getMessage() );
				} finally {
					remove_filter( $hook, $filter, 20 );
				}
			}
			foreach ( [ new \TypeError( 'constructor type' ), new ClientRegisterFail( 'constructor client failure', 99 ) ] as $cause ) {
				$throwing::$failure = $cause;
				$filter = static function () use ( $throwing ): string {
					return $throwing;
				};
				add_filter( $hook, $filter, 20 );
				try {
					new Client( 'extension-invalid-' . ++$this->next_client );
					self::fail( 'Expected ClientRegisterFail.' );
				} catch ( ClientRegisterFail $failure ) {
					self::assertSame( 4, $failure->getCode() );
					self::assertStringContainsString( $hook, $failure->getMessage() );
					self::assertStringContainsString( 'could not be constructed', $failure->getMessage() );
					self::assertSame( $cause, $failure->getPrevious() );
				} finally {
					remove_filter( $hook, $filter, 20 );
					$throwing::$failure = null;
				}
			}
		}
		self::assertSame( 0, ExtensionCompatibilityIncompatible::$constructions );
	}

	public function test_capable_custom_adapter_revalidates_parent_inside_atomic_update(): void
	{
		$client = $this->new_client();
		$relation = $this->register_post_relation( $client );
		$from = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$to = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$query = new ConnectionQuery( $from, $to );
		$query->set( 'title', 'same' );
		$connection = $relation->createConnection( $query );
		$storage = $client->getStorage();
		self::assertInstanceOf( ExtensionCompatibilityStorage::class, $storage );
		self::assertSame( $client, $relation->findConnections()->first()->getClient() );

		$connection->meta->fromArray( [ 'changed' => [ 'yes' ] ] );
		$connection->update();
		self::assertSame( [ 'changed' => [ 'yes' ] ], $storage->rows[ $connection->id ]->meta->toArray() );
		self::assertContains( 'update', $storage->calls );
		self::assertContains( 'meta:remove', $storage->calls );
		self::assertContains( 'meta:add', $storage->calls );

		$stale = clone $connection;
		$storage->before_atomic = static function () use ( $storage, $connection ): void {
			$storage->deleteSpecificConnections( $connection->id );
		};
		$before = count( $storage->calls );
		try {
			$stale->update();
			self::fail( 'Expected parent revalidation failure.' );
		} catch ( ConnectionNotFound $failure ) {
			self::assertSame( 0, $storage->deleteSpecificConnections( $connection->id ) );
		}
		self::assertSame( [ 'find', 'find', 'delete:id', 'atomic:start', 'update', 'atomic:rollback', 'delete:id' ], array_slice( $storage->calls, $before ) );
		self::assertArrayNotHasKey( $connection->id, $storage->rows );

		$second = $relation->createConnection( new ConnectionQuery( $from, $to ) );
		$second->title = 'updated';
		$second->meta->fromArray( [ 'later' => [ 'value' ] ] );
		$second->update();
		self::assertSame( 'updated', $storage->rows[ $second->id ]->title );
		self::assertSame( [ 'later' => [ 'value' ] ], $storage->rows[ $second->id ]->meta->toArray() );
		self::assertSame( 1, $storage->deleteSpecificConnections( $second->id ) );
		self::assertArrayNotHasKey( $second->id, $storage->rows );
	}

	public function test_all_eight_storage_operations_are_executable(): void
	{
		$client = $this->new_client();
		$storage = $client->getStorage();
		$query = new ConnectionQuery( 10, 20 );
		$query->set( 'relation', 'fixture' );
		$id = $storage->createConnection( $query );
		$meta = new MetaCollection();
		$meta->fromArray( [ 'key' => [ 'value' ] ] );
		$storage->addConnectionMeta( $id, $meta );
		self::assertCount( 1, $storage->findConnections( ( new ConnectionQuery() )->set( 'id', $id ) ) );
		self::assertSame( 1, $storage->removeConnectionMeta( $id, new MetaQueryCollection() ) );
		self::assertSame( 1, $storage->deleteDirectedConnections( 10, 20, 'fixture' ) );
		$id = $storage->createConnection( $query );
		self::assertSame( 1, $storage->deleteByObjectID( 10, 'fixture', true ) );
		$id = $storage->createConnection( $query );
		self::assertSame( 1, $storage->deleteSpecificConnections( $id ) );
	}

	private function new_client(): Client
	{
		$client = new Client( 'extension-contract-' . ++$this->next_client );
		$this->clients[] = $client;
		return $client;
	}

	private function register_post_relation( Client $client ): \iTRON\wpConnections\Relation
	{
		$query = new RelationQuery();
		$query->set( 'name', 'fixture' );
		$query->set( 'from', 'page' );
		$query->set( 'to', 'post' );
		$query->set( 'cardinality', 'm-m' );
		return $client->registerRelation( $query );
	}
}
