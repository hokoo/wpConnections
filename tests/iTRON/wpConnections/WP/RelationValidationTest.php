<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Exceptions\MissingParameters;
use iTRON\wpConnections\Exceptions\RelationWrongData;
use iTRON\wpConnections\Query\Connection;
use iTRON\wpConnections\Query\Relation;

class RelationValidationTest extends WPConnectionsTestCase
{
	public function test_rejects_relation_missing_only_to(): void
	{
		$relation = new Relation();
		$relation->set( 'name', 'missing-to' );
		$relation->set( 'from', 'page' );

		try {
			$this->client->registerRelation( $relation );
		} catch ( MissingParameters $exception ) {
			self::assertSame( [ 'to' ], $exception->getParams() );
			self::assertSame( 'Missing required fields: to ', $exception->getMessage() );

			return;
		}

		self::fail( 'Relation registration did not reject a missing to field.' );
	}

	/**
	 * @dataProvider missing_required_fields_provider
	 */
	public function test_rejects_every_other_missing_required_field_combination(
		array $provided_fields,
		array $expected_missing
	): void
	{
		$relation = new Relation();
		foreach ( $provided_fields as $field => $value ) {
			$relation->set( $field, $value );
		}

		try {
			$this->client->registerRelation( $relation );
		} catch ( MissingParameters $exception ) {
			self::assertSame( $expected_missing, $exception->getParams() );
			self::assertSame(
				'Missing required fields: ' . implode( ' ', $expected_missing ) . ' ',
				$exception->getMessage()
			);

			return;
		}

		self::fail( 'Relation registration accepted an incomplete definition.' );
	}

	public function missing_required_fields_provider(): array
	{
		return [
			'none provided'     => [ [], [ 'name', 'from', 'to' ] ],
			'only name'         => [ [ 'name' => 'only-name' ], [ 'from', 'to' ] ],
			'only from'         => [ [ 'from' => 'page' ], [ 'name', 'to' ] ],
			'only to'           => [ [ 'to' => 'post' ], [ 'name', 'from' ] ],
			'missing name'      => [ [ 'from' => 'page', 'to' => 'post' ], [ 'name' ] ],
			'missing only from' => [ [ 'name' => 'missing-from', 'to' => 'post' ], [ 'from' ] ],
		];
	}

	/**
	 * @dataProvider allowed_cardinality_provider
	 */
	public function test_accepts_allowed_cardinality_values( string $cardinality ): void
	{
		$relation = $this->register_relation(
			'cardinality-' . str_replace( '-', '', $cardinality ),
			[ 'cardinality' => $cardinality ]
		);

		self::assertSame( $cardinality, $relation->cardinality );
	}

	public function allowed_cardinality_provider(): array
	{
		return [
			'one to one'   => [ '1-1' ],
			'one to many'  => [ '1-m' ],
			'many to one'  => [ 'm-1' ],
			'many to many' => [ 'm-m' ],
		];
	}

	/**
	 * @dataProvider invalid_cardinality_provider
	 */
	public function test_rejects_unknown_cardinality_values( string $cardinality ): void
	{
		try {
			$this->register_relation( 'invalid-cardinality', [ 'cardinality' => $cardinality ] );
		} catch ( RelationWrongData $exception ) {
			self::assertSame( 400, $exception->getCode() );
			self::assertSame( [ $cardinality ], $exception->getParams() );
			self::assertSame(
				'Unknown relation cardinality: ' . $cardinality . ' ',
				$exception->getMessage()
			);

			return;
		}

		self::fail( 'Relation registration accepted an unknown cardinality.' );
	}

	public function invalid_cardinality_provider(): array
	{
		return [
			'empty'          => [ '' ],
			'incomplete'     => [ 'm' ],
			'unknown side'   => [ 'm-2' ],
			'wrong casing'   => [ '1-M' ],
			'verbose alias'  => [ 'one-to-many' ],
		];
	}

	public function test_rejects_duplicate_relation_name_with_stable_contract(): void
	{
		try {
			$this->register_relation( RELATION_0_NAME );
		} catch ( RelationWrongData $exception ) {
			self::assertSame( 400, $exception->getCode() );
			self::assertSame( [ RELATION_0_NAME ], $exception->getParams() );
			self::assertSame(
				'Relation has been already created. ' . RELATION_0_NAME . ' ',
				$exception->getMessage()
			);

			return;
		}

		self::fail( 'Relation registration accepted a duplicate name.' );
	}

	public function test_minimal_definition_uses_documented_defaults(): void
	{
		$relation = $this->register_relation( 'minimal-defaults' );

		self::assertSame(
			[
				'name'         => 'minimal-defaults',
				'from'         => 'page',
				'to'           => 'post',
				'type'         => 'both',
				'cardinality'  => 'm-m',
				'duplicatable' => false,
				'closurable'   => false,
			],
			$relation->toArray()
		);
	}

	/**
	 * @dataProvider legacy_type_provider
	 */
	public function test_legacy_type_is_serialized_no_op( string $type ): void
	{
		$relation = $this->register_relation(
			'legacy-type-' . $type,
			[ 'type' => $type ]
		);
		$connection = $relation->createConnection(
			new Connection( $this->page_ids[0], $this->post_ids[0] )
		);

		self::assertSame( $type, $relation->toArray()['type'] );
		self::assertSame( $this->page_ids[0], $connection->from );
		self::assertSame( $this->post_ids[0], $connection->to );
		self::assertSame(
			$connection->id,
			$relation->findConnections( new Connection( $this->page_ids[0], 0 ) )->first()->id
		);
		self::assertSame(
			$connection->id,
			$relation->findConnections( new Connection( 0, $this->post_ids[0] ) )->first()->id
		);
	}

	public function legacy_type_provider(): array
	{
		return [
			'from' => [ 'from' ],
			'to'   => [ 'to' ],
			'both' => [ 'both' ],
		];
	}

	private function register_relation( string $name, array $overrides = [] ): \iTRON\wpConnections\Relation
	{
		$relation = new Relation();
		$fields = array_merge(
			[
				'name' => $name,
				'from' => 'page',
				'to'   => 'post',
			],
			$overrides
		);

		foreach ( $fields as $field => $value ) {
			$relation->set( $field, $value );
		}

		return $this->client->registerRelation( $relation );
	}
}
