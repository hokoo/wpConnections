<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Abstracts\Connection as AbstractConnection;
use iTRON\wpConnections\Abstracts\Storage;
use iTRON\wpConnections\Client;
use iTRON\wpConnections\ConnectionCollection;
use iTRON\wpConnections\DebugLogObserver;
use iTRON\wpConnections\Internal\RestRouteRegistry;
use iTRON\wpConnections\MetaCollection;
use iTRON\wpConnections\Query\Connection as ConnectionQuery;
use iTRON\wpConnections\Query\MetaCollection as MetaQueryCollection;
use Psr\Log\AbstractLogger;

class DebugLogRecordingLogger extends AbstractLogger
{
	public static array $timeline = [];
	public array $records = [];
	public int $site_id;

	private Client $client;

	public function __construct( Client $client )
	{
		$this->client = $client;
		$this->site_id = get_current_blog_id();
	}

	public function log( $level, $message, array $context = [] ): void
	{
		$this->records[] = [ $level, $message, $context ];
		self::$timeline[] = 'log:' . $this->client->getName();
	}
}

class DebugLogEmittingStorage extends Storage
{
	private Client $client;

	public function __construct( Client $client )
	{
		$this->client = $client;
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
		do_action(
			'wpConnections/storage/deletedSpecificConnections',
			$this->client,
			$connection_ids,
			0
		);

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
		do_action(
			'wpConnections/storage/findConnections/dbQuery',
			'custom query',
			[ 'custom row' ],
			$this->client
		);

		return new ConnectionCollection();
	}

	public function addConnectionMeta( int $object_id, MetaCollection $meta_collection ): void
	{
	}

	public function removeConnectionMeta( int $object_id, MetaQueryCollection $meta_query )
	{
		do_action(
			'wpConnections/storage/removeConnectionMeta/after',
			$this->client,
			$object_id,
			$meta_query,
			'custom meta query',
			0
		);

		return 0;
	}
}

class DebugLogObserverTest extends \WP_UnitTestCase
{
	private array $clients = [];
	private $storage_filter;
	private $logger_filter;

	public function set_up()
	{
		parent::set_up();

		DebugLogRecordingLogger::$timeline = [];
		$this->storage_filter = static function (): string {
			return DebugLogEmittingStorage::class;
		};
		$this->logger_filter = static function (): string {
			return DebugLogRecordingLogger::class;
		};

		add_filter( 'wpConnections/factory/getStorage/class', $this->storage_filter );
		add_filter( 'wpConnections/factory/getLogger/class', $this->logger_filter );
	}

	public function tear_down()
	{
		remove_filter( 'wpConnections/factory/getLogger/class', $this->logger_filter );
		remove_filter( 'wpConnections/factory/getStorage/class', $this->storage_filter );

		foreach ( $this->clients as $client ) {
			if ( class_exists( RestRouteRegistry::class ) ) {
				RestRouteRegistry::instance()->deactivateClient( $client );
			}
			remove_action( 'deleted_post', [ $client->getStorage(), 'deleteByObjectID' ] );
		}

		parent::tear_down();
	}

	public function test_same_site_query_logs_once_to_origin_and_preserves_public_contract(): void
	{
		$first  = $this->new_client( 'debug-first' );
		$second = $this->new_client( 'debug-second' );
		$legacy_arguments = null;
		$opt_in_arguments = null;

		$before = static function ( $query, $rows ) use ( &$legacy_arguments ): void {
			$legacy_arguments = [ $query, $rows ];
			DebugLogRecordingLogger::$timeline[] = 'before';
		};
		$after = static function ( $query, $rows, $client ) use ( &$opt_in_arguments ): void {
			$opt_in_arguments = [ $query, $rows, $client ];
			DebugLogRecordingLogger::$timeline[] = 'after';
		};

		add_action( 'wpConnections/storage/findConnections/dbQuery', $before, 5, 2 );
		add_action( 'wpConnections/storage/findConnections/dbQuery', $after, 15, 3 );
		try {
			$first->getStorage()->findConnections( new ConnectionQuery() );
		} finally {
			remove_action( 'wpConnections/storage/findConnections/dbQuery', $after, 15 );
			remove_action( 'wpConnections/storage/findConnections/dbQuery', $before, 5 );
		}

		self::assertSame( [ 'custom query', [ 'custom row' ] ], $legacy_arguments );
		self::assertSame( [ 'custom query', [ 'custom row' ], $first ], $opt_in_arguments );
		self::assertSame( [ 'before', 'log:debug-first', 'after' ], DebugLogRecordingLogger::$timeline );
		self::assertSame(
			[
				[
					'debug',
					'wpConnections/storage/findConnections/dbQuery',
					[ 'custom query', [ 'custom row' ] ],
				],
			],
			$this->logger( $first )->records
		);
		self::assertSame( [], $this->logger( $second )->records );
		self::assertSame( 1, $this->observer_count( 'wpConnections/storage/findConnections/dbQuery' ) );
		self::assertSame( 1, $this->observer_count( 'wpConnections/storage/removeConnectionMeta/after' ) );
		self::assertSame( 1, $this->observer_count( 'wpConnections/storage/deletedSpecificConnections' ) );
	}

	public function test_mutation_events_keep_legacy_payload_and_route_only_to_origin(): void
	{
		$first  = $this->new_client( 'debug-mutation-first' );
		$second = $this->new_client( 'debug-mutation-second' );
		$meta_query = new MetaQueryCollection();

		$first->getStorage()->deleteSpecificConnections( [ 12, 13 ] );
		$first->getStorage()->removeConnectionMeta( 12, $meta_query );

		self::assertSame(
			[
				[
					'debug',
					'wpConnections/storage/deletedSpecificConnections',
					[ $first, [ 12, 13 ], 0 ],
				],
				[
					'debug',
					'wpConnections/storage/removeConnectionMeta/after',
					[ $first, 12, $meta_query, 'custom meta query', 0 ],
				],
			],
			$this->logger( $first )->records
		);
		self::assertSame( [], $this->logger( $second )->records );
	}

	public function test_invalid_or_missing_origin_skips_only_automatic_logging(): void
	{
		$client = $this->new_client( 'debug-invalid-origin' );
		$public_calls = 0;
		$listener = static function () use ( &$public_calls ): void {
			$public_calls++;
		};

		add_action( 'wpConnections/storage/findConnections/dbQuery', $listener, 20, 3 );
		add_action( 'wpConnections/storage/removeConnectionMeta/after', $listener, 20, 5 );
		add_action( 'wpConnections/storage/deletedSpecificConnections', $listener, 20, 3 );
		try {
			do_action( 'wpConnections/storage/findConnections/dbQuery', 'query', [] );
			do_action( 'wpConnections/storage/findConnections/dbQuery', 'query', [], new \stdClass() );
			do_action( 'wpConnections/storage/removeConnectionMeta/after', null, 1, null, 'query', 0 );
			do_action( 'wpConnections/storage/deletedSpecificConnections', 'not-a-client', [ 1 ], 0 );
		} finally {
			remove_action( 'wpConnections/storage/deletedSpecificConnections', $listener, 20 );
			remove_action( 'wpConnections/storage/removeConnectionMeta/after', $listener, 20 );
			remove_action( 'wpConnections/storage/findConnections/dbQuery', $listener, 20 );
		}

		self::assertSame( 4, $public_calls );
		self::assertSame( [], $this->logger( $client )->records );
	}

	public function test_client_created_after_switch_to_blog_uses_only_its_own_logger(): void
	{
		if ( ! function_exists( 'switch_to_blog' ) ) {
			require_once ABSPATH . WPINC . '/ms-blogs.php';
		}

		$first = $this->new_client( 'debug-site-first' );
		$first_site_id = get_current_blog_id();
		$second_site_id = $first_site_id + 1;

		\switch_to_blog( $second_site_id );
		try {
			self::assertSame( $second_site_id, get_current_blog_id() );
			$second = $this->new_client( 'debug-site-second' );
			$second->getStorage()->findConnections( new ConnectionQuery() );
		} finally {
			\restore_current_blog();
		}

		self::assertSame( $first_site_id, $this->logger( $first )->site_id );
		self::assertSame( $second_site_id, $this->logger( $second )->site_id );
		self::assertSame( [], $this->logger( $first )->records );
		self::assertCount( 1, $this->logger( $second )->records );
	}

	private function new_client( string $name ): Client
	{
		$client = new Client( $name );
		$this->clients[] = $client;

		return $client;
	}

	private function logger( Client $client ): DebugLogRecordingLogger
	{
		$logger = $client->getLogger();
		self::assertInstanceOf( DebugLogRecordingLogger::class, $logger );

		return $logger;
	}

	private function observer_count( string $hook ): int
	{
		global $wp_filter;

		$count = 0;
		foreach ( $wp_filter[ $hook ]->callbacks[10] ?? [] as $registered ) {
			$callback = $registered['function'];
			if ( is_array( $callback ) && $callback[0] instanceof DebugLogObserver ) {
				$count++;
			}
		}

		return $count;
	}
}
