<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Connection;
use iTRON\wpConnections\Exceptions\ConnectionWrongData;
use iTRON\wpConnections\Exceptions\MissingParameters;
use iTRON\wpConnections\Query\Connection as ConnectionQuery;
use iTRON\wpConnections\Query\Relation as RelationQuery;

class ConnectionErrorContractTest extends WPConnectionsTestCase
{
	/**
	 * @dataProvider missing_endpoint_provider
	 */
	public function test_reports_only_missing_connection_endpoints(
		bool $provide_from,
		bool $provide_to,
		array $expected_params,
		string $expected_message
	): void {
		$relation = $this->register_error_relation( 'missing-endpoint' );
		$query = new ConnectionQuery(
			$provide_from ? $this->post_ids[0] : 0,
			$provide_to ? $this->post_ids[1] : 0
		);

		$exception = $this->capture_exception(
			static function () use ( $relation, $query ): void {
				$relation->createConnection( $query );
			}
		);

		self::assertSame( MissingParameters::class, get_class( $exception ) );
		self::assertSame( 4, $exception->getCode() );
		self::assertSame( $expected_message, $exception->getMessage() );
		self::assertSame( $expected_params, $exception->getParams() );
		self::assertCount( 0, $relation->findConnections() );
	}

	public function missing_endpoint_provider(): array
	{
		return [
			'missing from' => [ false, true, [ 'from' ], 'Missing required fields: from ' ],
			'missing to'   => [ true, false, [ 'to' ], 'Missing required fields: to ' ],
			'missing both' => [ false, false, [ 'from', 'to' ], 'Missing required fields: from to ' ],
		];
	}

	public function test_rejects_forbidden_self_connection_with_code_301(): void
	{
		$relation = $this->register_error_relation(
			'closed-relation',
			[ 'closurable' => false ]
		);

		$this->assert_connection_error(
			301,
			'Closurable not allowed by relation settings.',
			function () use ( $relation ): void {
				$relation->createConnection(
					new ConnectionQuery( $this->post_ids[0], $this->post_ids[0] )
				);
			}
		);

		self::assertCount( 0, $relation->findConnections() );
	}

	public function test_allows_self_connection_when_relation_is_closurable(): void
	{
		$relation = $this->register_error_relation(
			'closurable-relation',
			[ 'closurable' => true ]
		);

		$connection = $relation->createConnection(
			new ConnectionQuery( $this->post_ids[0], $this->post_ids[0] )
		);

		self::assertGreaterThan( 0, $connection->id );
		self::assertSame( $this->post_ids[0], $connection->from );
		self::assertSame( $this->post_ids[0], $connection->to );
		self::assertCount( 1, $relation->findConnections() );
	}

	public function test_duplicate_wins_before_cardinality_with_code_303(): void
	{
		$relation = $this->register_error_relation(
			'duplicate-before-cardinality',
			[
				'cardinality' => '1-1',
				'closurable'  => true,
			]
		);
		$relation->createConnection(
			new ConnectionQuery( $this->post_ids[0], $this->post_ids[1] )
		);

		$this->assert_connection_error(
			303,
			'Duplicatable violation.',
			function () use ( $relation ): void {
				$relation->createConnection(
					new ConnectionQuery( $this->post_ids[0], $this->post_ids[1] )
				);
			}
		);

		self::assertCount( 1, $relation->findConnections() );
	}

	public function test_reports_standalone_cardinality_violation_with_code_302(): void
	{
		$relation = $this->register_error_relation(
			'cardinality-error',
			[
				'cardinality' => '1-1',
				'closurable'  => true,
			]
		);
		$relation->createConnection(
			new ConnectionQuery( $this->post_ids[0], $this->post_ids[1] )
		);

		$this->assert_connection_error(
			302,
			'Cardinality violation.',
			function () use ( $relation ): void {
				$relation->createConnection(
					new ConnectionQuery( $this->post_ids[1], $this->post_ids[1] )
				);
			}
		);

		self::assertCount( 1, $relation->findConnections() );
	}

	public function test_rejects_connection_update_without_id_with_code_304(): void
	{
		$connection = new Connection(
			new ConnectionQuery( $this->post_ids[0], $this->post_ids[1] )
		);

		$this->assert_connection_error(
			304,
			'Cannot update uninitialized connection',
			static function () use ( $connection ): void {
				$connection->update();
			}
		);
	}

	private function register_error_relation( string $name, array $overrides = [] ): \iTRON\wpConnections\Relation
	{
		$query = new RelationQuery();
		$fields = array_merge(
			[
				'name'         => $name,
				'from'         => 'post',
				'to'           => 'post',
				'cardinality'  => 'm-m',
				'duplicatable' => false,
				'closurable'   => false,
			],
			$overrides
		);

		foreach ( $fields as $field => $value ) {
			$query->set( $field, $value );
		}

		return $this->client->registerRelation( $query );
	}

	private function assert_connection_error( int $code, string $message, callable $operation ): void
	{
		$exception = $this->capture_exception( $operation );

		self::assertSame( ConnectionWrongData::class, get_class( $exception ) );
		self::assertSame( $code, $exception->getCode() );
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
