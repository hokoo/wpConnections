<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Connection;
use iTRON\wpConnections\ConnectionCollection;
use iTRON\wpConnections\Exceptions\ConnectionWrongData;
use iTRON\wpConnections\Query\Connection as ConnectionQuery;
use iTRON\wpConnections\Query\Relation as RelationQuery;

class ConnectionDeleteTest extends WPConnectionsTestCase
{
	private array $prepare_warnings = [];

	public function set_up()
	{
		parent::set_up();
		add_action( 'doing_it_wrong_run', [ $this, 'record_prepare_warning' ], 10, 3 );
	}

	public function tear_down()
	{
		try {
			self::assertSame( [], $this->prepare_warnings, implode( "\n", $this->prepare_warnings ) );
		} finally {
			remove_action( 'doing_it_wrong_run', [ $this, 'record_prepare_warning' ], 10 );
			$this->client->disablePostDeletionCleanup();
			parent::tear_down();
		}
	}

	public function record_prepare_warning( string $function_name, string $message, int $error_level ): void
	{
		if ( 'wpdb::prepare' === $function_name ) {
			$this->prepare_warnings[] = $message . ' (level ' . $error_level . ')';
		}
	}

	public function test_relation_id_delete_is_scoped_while_direct_spi_remains_client_wide(): void
	{
		$other_relation = 'delete-id-other-relation';
		$this->register_relation( $other_relation );

		$owned = $this->create_connection(
			RELATION_0_NAME,
			$this->page_ids[0],
			$this->post_ids[0],
			[ 'owner' => [ 'owned', 'duplicate' ] ]
		);
		$foreign = $this->create_connection(
			$other_relation,
			$this->page_ids[0],
			$this->post_ids[1],
			[ 'owner' => [ 'foreign', 'duplicate' ] ]
		);

		$query = new ConnectionQuery();
		$query->set( 'id', $foreign->id );
		$query->set( 'both', $owned->from );
		self::assertSame( 0, $this->client->getRelation( RELATION_0_NAME )->detachConnections( $query ) );
		$this->assert_connection_ids( [ $owned->id ], $this->find_connections( RELATION_0_NAME ) );
		$this->assert_connection_ids( [ $foreign->id ], $this->find_connections( $other_relation ) );
		self::assertSame( 2, $this->meta_row_count( $foreign->id ) );

		$query->set( 'id', $owned->id );
		self::assertSame( 1, $this->client->getRelation( RELATION_0_NAME )->detachConnections( $query ) );
		self::assertTrue( $this->find_connections( RELATION_0_NAME )->isEmpty() );
		self::assertSame( 0, $this->meta_row_count( $owned->id ) );

		self::assertSame( 1, $this->client->getStorage()->deleteSpecificConnections( $foreign->id ) );
		self::assertTrue( $this->find_connections( $other_relation )->isEmpty() );
		self::assertSame( 0, $this->meta_row_count( $foreign->id ) );
	}

	public function test_domain_delete_preserves_historical_selector_precedence(): void
	{
		$id_target = $this->create_connection(
			RELATION_0_NAME,
			$this->page_ids[0],
			$this->post_ids[0]
		);
		$lower_priority_target = $this->create_connection(
			RELATION_0_NAME,
			$this->page_ids[0],
			$this->post_ids[1]
		);

		$query = new ConnectionQuery();
		$query->set( 'id', $id_target->id );
		$query->set( 'both', -1 );
		$query->set( 'from', $lower_priority_target->from );
		$query->set( 'to', $lower_priority_target->to );

		self::assertSame( 1, $this->client->getRelation( RELATION_0_NAME )->detachConnections( $query ) );
		$this->assert_connection_ids(
			[ $lower_priority_target->id ],
			$this->find_connections( RELATION_0_NAME )
		);

		$both_target_page = $this->create_entity( 'page', 'Both target page' );
		$pair_target_page = $this->create_entity( 'page', 'Pair target page' );
		$both_target = $this->create_connection(
			RELATION_0_NAME,
			$both_target_page,
			$this->post_ids[0]
		);
		$pair_target = $this->create_connection(
			RELATION_0_NAME,
			$pair_target_page,
			$this->post_ids[0]
		);

		$query = new ConnectionQuery( $pair_target->from, $pair_target->to );
		$query->set( 'both', $both_target_page );
		self::assertSame( 1, $this->client->getRelation( RELATION_0_NAME )->detachConnections( $query ) );
		$this->assert_connection_ids(
			[ $lower_priority_target->id, $pair_target->id ],
			$this->find_connections( RELATION_0_NAME )
		);
		self::assertSame( 0, $this->meta_row_count( $both_target->id ) );
	}

	/**
	 * @dataProvider invalid_domain_id_provider
	 */
	public function test_invalid_selected_id_does_not_fall_through_to_valid_lower_priority_fields( $invalid_id ): void
	{
		$connection = $this->create_connection(
			RELATION_0_NAME,
			$this->page_ids[0],
			$this->post_ids[0]
		);
		$query = new ConnectionQuery();
		$query->set( 'id', $invalid_id );
		$query->set( 'both', $connection->from );

		global $wpdb;
		$queries_before = $wpdb->num_queries;
		try {
			$this->client->getRelation( RELATION_0_NAME )->detachConnections( $query );
			self::fail( 'Expected the selected ID selector to be rejected.' );
		} catch ( ConnectionWrongData $exception ) {
			self::assertSame( 'Positive integer ID expected.', $exception->getMessage() );
			self::assertSame( $queries_before, $wpdb->num_queries );
		}

		$this->assert_connection_ids( [ $connection->id ], $this->find_connections( RELATION_0_NAME ) );
		self::assertSame( 1, $this->meta_row_count( $connection->id ) );
	}

	public function invalid_domain_id_provider(): array
	{
		return [
			'explicit zero'       => [ 0 ],
			'negative integer'    => [ -1 ],
			'float'               => [ 1.5 ],
			'exponent string'     => [ '1e3' ],
			'boolean'             => [ true ],
			'whitespace string'   => [ ' 1' ],
			'plus string'         => [ '+1' ],
			'overflow string'     => [ (string) PHP_INT_MAX . '0' ],
			'incompatible array'  => [ [ 1 ] ],
			'incompatible object' => [ new \stdClass() ],
			'explicit null'       => [ null ],
		];
	}

	/**
	 * @dataProvider invalid_domain_branch_provider
	 */
	public function test_invalid_selected_domain_branch_rejects_raw_value_before_sql(
		string $branch,
		$invalid
	): void {
		$connection = $this->create_connection(
			RELATION_0_NAME,
			$this->page_ids[0],
			$this->post_ids[0]
		);

		switch ( $branch ) {
			case 'both':
				$query = new ConnectionQuery( $connection->from, $connection->to );
				$query->set( 'both', $invalid );
				break;
			case 'pair-from':
				$query = new ConnectionQuery( $invalid, $connection->to );
				break;
			case 'pair-to':
				$query = new ConnectionQuery( $connection->from, $invalid );
				break;
			case 'from':
				$query = new ConnectionQuery();
				$query->set( 'from', $invalid );
				break;
			case 'to':
				$query = new ConnectionQuery();
				$query->set( 'to', $invalid );
				break;
			default:
				self::fail( 'Unknown delete selector branch.' );
		}

		global $wpdb;
		$queries_before = $wpdb->num_queries;
		try {
			$this->client->getRelation( RELATION_0_NAME )->detachConnections( $query );
			self::fail( 'Expected the selected domain selector to be rejected.' );
		} catch ( ConnectionWrongData $exception ) {
			self::assertSame( 'Positive integer ID expected.', $exception->getMessage() );
			self::assertSame( $queries_before, $wpdb->num_queries );
		}

		$this->assert_connection_ids( [ $connection->id ], $this->find_connections( RELATION_0_NAME ) );
		self::assertSame( 1, $this->meta_row_count( $connection->id ) );
	}

	public function invalid_domain_branch_provider(): array
	{
		return [
			'both exponent string'   => [ 'both', '1e3' ],
			'both incompatible value' => [ 'both', new \stdClass() ],
			'pair float from'         => [ 'pair-from', 1.5 ],
			'pair boolean to'         => [ 'pair-to', true ],
			'from whitespace string'  => [ 'from', ' 1' ],
			'from overflow string'    => [ 'from', (string) PHP_INT_MAX . '0' ],
			'to plus string'          => [ 'to', '+1' ],
			'to incompatible value'   => [ 'to', [ 1 ] ],
		];
	}

	public function test_valid_higher_priority_selector_ignores_incompatible_lower_priority_value(): void
	{
		$target = $this->create_connection(
			RELATION_0_NAME,
			$this->page_ids[0],
			$this->post_ids[0]
		);
		$preserved = $this->create_connection(
			RELATION_0_NAME,
			$this->page_ids[0],
			$this->post_ids[1]
		);

		$query = new ConnectionQuery();
		$query->set( 'id', $target->id );
		$query->set( 'both', new \stdClass() );
		$query->set( 'from', [ $preserved->from ] );

		self::assertSame( 1, $this->client->getRelation( RELATION_0_NAME )->detachConnections( $query ) );
		$this->assert_connection_ids( [ $preserved->id ], $this->find_connections( RELATION_0_NAME ) );
	}

	public function test_direct_invalid_id_write_is_preserved_until_selected_branch_validation(): void
	{
		$connection = $this->create_connection(
			RELATION_0_NAME,
			$this->page_ids[0],
			$this->post_ids[0]
		);
		$query       = new ConnectionQuery();
		$query->id   = 1.5;
		$query->both = $connection->from;

		global $wpdb;
		$queries_before = $wpdb->num_queries;
		try {
			$this->client->getRelation( RELATION_0_NAME )->detachConnections( $query );
			self::fail( 'Expected the direct ID selector to be rejected.' );
		} catch ( ConnectionWrongData $exception ) {
			self::assertSame( 'Positive integer ID expected.', $exception->getMessage() );
			self::assertSame( $queries_before, $wpdb->num_queries );
		}

		$this->assert_connection_ids( [ $connection->id ], $this->find_connections( RELATION_0_NAME ) );
	}

	public function test_repeated_setter_write_validates_the_latest_raw_selector_value(): void
	{
		$connection = $this->create_connection(
			RELATION_0_NAME,
			$this->page_ids[0],
			$this->post_ids[0]
		);
		$query = new ConnectionQuery();
		$query->set( 'id', $connection->id );
		$query->set( 'id', 1.5 );
		$query->set( 'both', $connection->from );

		global $wpdb;
		$queries_before = $wpdb->num_queries;
		try {
			$this->client->getRelation( RELATION_0_NAME )->detachConnections( $query );
			self::fail( 'Expected the latest raw ID selector value to be rejected.' );
		} catch ( ConnectionWrongData $exception ) {
			self::assertSame( 'Positive integer ID expected.', $exception->getMessage() );
			self::assertSame( $queries_before, $wpdb->num_queries );
		}

		$this->assert_connection_ids( [ $connection->id ], $this->find_connections( RELATION_0_NAME ) );
	}

	public function test_invalid_selected_both_does_not_fall_through_to_valid_pair(): void
	{
		$connection = $this->create_connection(
			RELATION_0_NAME,
			$this->page_ids[0],
			$this->post_ids[0]
		);
		$query = new ConnectionQuery( $connection->from, $connection->to );
		$query->set( 'both', 0 );

		$this->expectException( ConnectionWrongData::class );
		$this->expectExceptionMessage( 'Positive integer ID expected.' );
		try {
			$this->client->getRelation( RELATION_0_NAME )->detachConnections( $query );
		} finally {
			$this->assert_connection_ids( [ $connection->id ], $this->find_connections( RELATION_0_NAME ) );
			self::assertSame( 1, $this->meta_row_count( $connection->id ) );
		}
	}

	public function test_invalid_explicit_pair_does_not_fall_back_to_from_only(): void
	{
		$connection = $this->create_connection(
			RELATION_0_NAME,
			$this->page_ids[0],
			$this->post_ids[0]
		);
		$query = new ConnectionQuery( $connection->from, 0 );

		$this->expectException( ConnectionWrongData::class );
		$this->expectExceptionMessage( 'Positive integer ID expected.' );
		try {
			$this->client->getRelation( RELATION_0_NAME )->detachConnections( $query );
		} finally {
			$this->assert_connection_ids( [ $connection->id ], $this->find_connections( RELATION_0_NAME ) );
			self::assertSame( 1, $this->meta_row_count( $connection->id ) );
		}
	}

	public function test_domain_pair_from_and_to_branches_delete_only_their_matches(): void
	{
		$relation_name = 'delete-domain-branches';
		$this->register_relation( $relation_name, 'page', 'post', true );
		$other_page = $this->create_entity( 'page', 'Domain branch page' );
		$pair_first = $this->create_connection(
			$relation_name,
			$this->page_ids[0],
			$this->post_ids[0]
		);
		$pair_second = $this->create_connection(
			$relation_name,
			$this->page_ids[0],
			$this->post_ids[0]
		);
		$from_only_target = $this->create_connection(
			$relation_name,
			$this->page_ids[0],
			$this->post_ids[1]
		);
		$to_only_target = $this->create_connection(
			$relation_name,
			$other_page,
			$this->post_ids[0]
		);

		self::assertSame(
			2,
			$this->client->getRelation( $relation_name )->detachConnections(
				new ConnectionQuery( $this->page_ids[0], $this->post_ids[0] )
			)
		);
		$this->assert_connection_ids(
			[ $from_only_target->id, $to_only_target->id ],
			$this->find_connections( $relation_name )
		);

		$from_query = new ConnectionQuery();
		$from_query->set( 'from', $this->page_ids[0] );
		self::assertSame(
			1,
			$this->client->getRelation( $relation_name )->detachConnections( $from_query )
		);
		$this->assert_connection_ids( [ $to_only_target->id ], $this->find_connections( $relation_name ) );

		$to_query = new ConnectionQuery();
		$to_query->set( 'to', $this->post_ids[0] );
		self::assertSame(
			1,
			$this->client->getRelation( $relation_name )->detachConnections( $to_query )
		);
		self::assertTrue( $this->find_connections( $relation_name )->isEmpty() );
		foreach ( [ $pair_first, $pair_second, $from_only_target, $to_only_target ] as $connection ) {
			self::assertSame( 0, $this->meta_row_count( $connection->id ) );
		}
	}

	public function test_direct_id_delete_normalizes_duplicates_and_keeps_partial_match_count(): void
	{
		$first = $this->create_connection(
			RELATION_0_NAME,
			$this->page_ids[0],
			$this->post_ids[0],
			[ 'tag' => [ 'one', 'two' ] ]
		);
		$second = $this->create_connection(
			RELATION_0_NAME,
			$this->page_ids[0],
			$this->post_ids[1],
			[ 'tag' => [ 'three', 'four' ] ]
		);

		$deleted = $this->client->getStorage()->deleteSpecificConnections(
			[ $first->id, str_pad( (string) $first->id, 8, '0', STR_PAD_LEFT ), $second->id, 999999999 ]
		);

		self::assertSame( 2, $deleted );
		self::assertTrue( $this->find_connections( RELATION_0_NAME )->isEmpty() );
		self::assertSame( 0, $this->meta_row_count( $first->id ) );
		self::assertSame( 0, $this->meta_row_count( $second->id ) );
		self::assertSame( 0, $this->client->getStorage()->deleteSpecificConnections( 999999999 ) );
	}

	/**
	 * @dataProvider invalid_id_selector_provider
	 */
	public function test_direct_id_delete_rejects_invalid_input_before_mutation( $invalid ): void
	{
		$connection = $this->create_connection(
			RELATION_0_NAME,
			$this->page_ids[0],
			$this->post_ids[0],
			[ 'owner' => [ 'preserved' ] ]
		);

		try {
			global $wpdb;
			$queries_before = $wpdb->num_queries;
			$this->client->getStorage()->deleteSpecificConnections( $invalid );
			self::fail( 'Expected invalid connection IDs to be rejected.' );
		} catch ( ConnectionWrongData $exception ) {
			self::assertSame( 'Positive integer ID or a non-empty array of positive integer IDs expected.', $exception->getMessage() );
			self::assertSame( $queries_before, $wpdb->num_queries );
		}

		$this->assert_connection_ids( [ $connection->id ], $this->find_connections( RELATION_0_NAME ) );
		self::assertSame( 1, $this->meta_row_count( $connection->id ) );
	}

	public function invalid_id_selector_provider(): array
	{
		return [
			'zero integer'       => [ 0 ],
			'negative integer'   => [ -1 ],
			'float'              => [ 1.5 ],
			'exponent string'    => [ '1e3' ],
			'negative string'    => [ '-1' ],
			'plus string'        => [ '+1' ],
			'whitespace string'  => [ ' 1' ],
			'overflow string'    => [ (string) PHP_INT_MAX . '0' ],
			'boolean'            => [ true ],
			'null'               => [ null ],
			'empty array'        => [ [] ],
			'all invalid array'  => [ [ 'invalid' ] ],
			'mixed array'        => [ [ 1, 'invalid' ] ],
			'zero in array'      => [ [ 1, 0 ] ],
			'float in array'     => [ [ 1, 2.5 ] ],
			'arbitrary object'   => [ new \stdClass() ],
		];
	}

	public function test_object_delete_honors_direction_exact_relation_and_self_count(): void
	{
		$relation_name = 'delete-object-direction';
		$this->register_relation( $relation_name, 'post', 'post', true, true );
		$third_post = $this->create_entity( 'post', 'Third post' );

		$incoming = $this->create_connection(
			$relation_name,
			$this->post_ids[0],
			$this->post_ids[1],
			[ 'kind' => [ 'incoming' ] ]
		);
		$outgoing = $this->create_connection(
			$relation_name,
			$this->post_ids[1],
			$third_post,
			[ 'kind' => [ 'outgoing' ] ]
		);
		$self = $this->create_connection(
			$relation_name,
			$this->post_ids[1],
			$this->post_ids[1],
			[ 'kind' => [ 'self' ] ]
		);

		self::assertSame(
			2,
			$this->client->getStorage()->deleteByObjectID(
				$this->post_ids[1],
				$relation_name,
				true,
				false
			)
		);
		$this->assert_connection_ids( [ $incoming->id ], $this->find_connections( $relation_name ) );
		self::assertSame( 0, $this->meta_row_count( $outgoing->id ) );
		self::assertSame( 0, $this->meta_row_count( $self->id ) );
		self::assertSame( 1, $this->meta_row_count( $incoming->id ) );
	}

	public function test_object_delete_both_sides_counts_self_once(): void
	{
		$relation_name = 'delete-object-both';
		$this->register_relation( $relation_name, 'post', 'post', true, true );
		$third_post = $this->create_entity( 'post', 'Both third post' );
		$connections = [
			$this->create_connection( $relation_name, $this->post_ids[0], $this->post_ids[1] ),
			$this->create_connection( $relation_name, $this->post_ids[1], $third_post ),
			$this->create_connection( $relation_name, $this->post_ids[1], $this->post_ids[1] ),
		];

		self::assertSame(
			3,
			$this->client->getStorage()->deleteByObjectID( $this->post_ids[1], $relation_name )
		);
		self::assertTrue( $this->find_connections( $relation_name )->isEmpty() );
		foreach ( $connections as $connection ) {
			self::assertSame( 0, $this->meta_row_count( $connection->id ) );
		}
	}

	public function test_object_delete_only_to_preserves_outgoing_rows(): void
	{
		$relation_name = 'delete-object-only-to';
		$this->register_relation( $relation_name, 'post', 'post', true, true );
		$third_post = $this->create_entity( 'post', 'Only-to third post' );
		$incoming = $this->create_connection(
			$relation_name,
			$this->post_ids[0],
			$this->post_ids[1]
		);
		$outgoing = $this->create_connection(
			$relation_name,
			$this->post_ids[1],
			$third_post
		);
		$self = $this->create_connection(
			$relation_name,
			$this->post_ids[1],
			$this->post_ids[1]
		);

		self::assertSame(
			2,
			$this->client->getStorage()->deleteByObjectID(
				$this->post_ids[1],
				$relation_name,
				false,
				true
			)
		);
		$this->assert_connection_ids( [ $outgoing->id ], $this->find_connections( $relation_name ) );
		self::assertSame( 0, $this->meta_row_count( $incoming->id ) );
		self::assertSame( 0, $this->meta_row_count( $self->id ) );
		self::assertSame( 1, $this->meta_row_count( $outgoing->id ) );
	}

	public function test_object_delete_rejects_conflicting_flags_without_mutation(): void
	{
		$connection = $this->create_connection(
			RELATION_0_NAME,
			$this->page_ids[0],
			$this->post_ids[0]
		);

		$this->expectException( ConnectionWrongData::class );
		$this->expectExceptionMessage( 'Only one object-side direction restriction may be enabled.' );
		try {
			$this->client->getStorage()->deleteByObjectID(
				$this->page_ids[0],
				RELATION_0_NAME,
				true,
				true
			);
		} finally {
			$this->assert_connection_ids( [ $connection->id ], $this->find_connections( RELATION_0_NAME ) );
			self::assertSame( 1, $this->meta_row_count( $connection->id ) );
		}
	}

	public function test_relation_filter_is_exact_for_object_and_directed_deletes(): void
	{
		$first_relation = 'delete-exact-alpha';
		$second_relation = 'delete-exact-alphabeta';
		$this->register_relation( $first_relation, 'page', 'post', true );
		$this->register_relation( $second_relation, 'page', 'post', true );

		$first = $this->create_connection(
			$first_relation,
			$this->page_ids[0],
			$this->post_ids[0]
		);
		$second = $this->create_connection(
			$second_relation,
			$this->page_ids[0],
			$this->post_ids[0]
		);

		self::assertSame(
			0,
			$this->client->getStorage()->deleteByObjectID(
				$this->page_ids[0],
				'delete-exact-alpha%',
				true
			)
		);
		self::assertSame(
			0,
			$this->client->getStorage()->deleteDirectedConnections(
				$this->page_ids[0],
				$this->post_ids[0],
				'delete-exact_alpha'
			)
		);
		$this->assert_connection_ids( [ $first->id ], $this->find_connections( $first_relation ) );
		$this->assert_connection_ids( [ $second->id ], $this->find_connections( $second_relation ) );

		self::assertSame(
			1,
			$this->client->getStorage()->deleteByObjectID(
				$this->page_ids[0],
				$first_relation,
				true
			)
		);
		self::assertSame(
			1,
			$this->client->getStorage()->deleteDirectedConnections(
				$this->page_ids[0],
				$this->post_ids[0],
				$second_relation
			)
		);
		self::assertSame( 0, $this->meta_row_count( $first->id ) );
		self::assertSame( 0, $this->meta_row_count( $second->id ) );
	}

	public function test_empty_direct_spi_relation_keeps_all_relations_scope(): void
	{
		$first_relation = 'delete-all-relations-first';
		$second_relation = 'delete-all-relations-second';
		$this->register_relation( $first_relation, 'page', 'post', true );
		$this->register_relation( $second_relation, 'page', 'post', true );

		$directed = [
			$this->create_connection( $first_relation, $this->page_ids[0], $this->post_ids[0] ),
			$this->create_connection( $second_relation, $this->page_ids[0], $this->post_ids[0] ),
		];
		self::assertSame(
			2,
			$this->client->getStorage()->deleteDirectedConnections(
				$this->page_ids[0],
				$this->post_ids[0]
			)
		);
		foreach ( $directed as $connection ) {
			self::assertSame( 0, $this->meta_row_count( $connection->id ) );
		}

		$by_object = [
			$this->create_connection( $first_relation, $this->page_ids[0], $this->post_ids[1] ),
			$this->create_connection( $second_relation, $this->page_ids[0], $this->post_ids[1] ),
		];
		self::assertSame(
			2,
			$this->client->getStorage()->deleteByObjectID( $this->page_ids[0], '', true )
		);
		foreach ( $by_object as $connection ) {
			self::assertSame( 0, $this->meta_row_count( $connection->id ) );
		}
		self::assertTrue( $this->find_connections( $first_relation )->isEmpty() );
		self::assertTrue( $this->find_connections( $second_relation )->isEmpty() );
	}

	public function test_directed_delete_removes_all_duplicate_rows_and_their_metadata(): void
	{
		$relation_name = 'delete-directed-duplicates';
		$other_relation = 'delete-directed-other';
		$this->register_relation( $relation_name, 'page', 'post', true );
		$this->register_relation( $other_relation, 'page', 'post', true );

		$first = $this->create_connection(
			$relation_name,
			$this->page_ids[0],
			$this->post_ids[0],
			[ 'tag' => [ 'first', 'shared' ] ]
		);
		$second = $this->create_connection(
			$relation_name,
			$this->page_ids[0],
			$this->post_ids[0],
			[ 'tag' => [ 'second', 'shared' ] ]
		);
		$foreign = $this->create_connection(
			$other_relation,
			$this->page_ids[0],
			$this->post_ids[0],
			[ 'tag' => [ 'foreign' ] ]
		);

		self::assertSame(
			2,
			$this->client->getStorage()->deleteDirectedConnections(
				$this->page_ids[0],
				$this->post_ids[0],
				$relation_name
			)
		);
		self::assertTrue( $this->find_connections( $relation_name )->isEmpty() );
		$this->assert_connection_ids( [ $foreign->id ], $this->find_connections( $other_relation ) );
		self::assertSame( 0, $this->meta_row_count( $first->id ) );
		self::assertSame( 0, $this->meta_row_count( $second->id ) );
		self::assertSame( 1, $this->meta_row_count( $foreign->id ) );
		self::assertSame(
			0,
			$this->client->getStorage()->deleteDirectedConnections(
				$this->page_ids[0],
				$this->post_ids[1],
				$relation_name
			)
		);
	}

	/**
	 * @dataProvider invalid_directed_selector_provider
	 */
	public function test_directed_delete_rejects_invalid_selected_endpoint( $from, $to ): void
	{
		$this->expectException( ConnectionWrongData::class );
		$this->expectExceptionMessage( 'Positive integer ID expected.' );
		$this->client->getStorage()->deleteDirectedConnections( $from, $to, RELATION_0_NAME );
	}

	public function invalid_directed_selector_provider(): array
	{
		return [
			'zero from'     => [ 0, 1 ],
			'negative from' => [ -1, 1 ],
			'float from'    => [ 1.5, 1 ],
			'exponent from' => [ '1e3', 1 ],
			'boolean from'  => [ true, 1 ],
			'null from'     => [ null, 1 ],
			'zero to'       => [ 1, 0 ],
			'negative to'   => [ 1, -1 ],
			'float to'      => [ 1, 1.5 ],
			'exponent to'   => [ 1, '1e3' ],
		];
	}

	public function test_empty_domain_selector_is_invalid(): void
	{
		$this->expectException( ConnectionWrongData::class );
		$this->expectExceptionMessage( 'A connection delete selector is required.' );
		$this->client->getRelation( RELATION_0_NAME )->detachConnections( new ConnectionQuery() );
	}

	private function create_connection(
		string $relation,
		int $from,
		int $to,
		array $metadata = [ 'marker' => [ 'value' ] ]
	): Connection {
		$query = new ConnectionQuery( $from, $to );
		foreach ( $metadata as $key => $values ) {
			foreach ( $values as $value ) {
				$query->meta->add( new \iTRON\wpConnections\Query\Meta( $key, $value ) );
			}
		}

		return $this->client->getRelation( $relation )->createConnection( $query );
	}

	private function create_entity( string $post_type, string $title ): int
	{
		return self::factory()->post->create(
			[
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_type'   => $post_type,
			]
		);
	}

	private function register_relation(
		string $name,
		string $from = 'page',
		string $to = 'post',
		bool $duplicatable = true,
		bool $closurable = false
	): void {
		$query = new RelationQuery();
		$query->set( 'name', $name );
		$query->set( 'from', $from );
		$query->set( 'to', $to );
		$query->set( 'cardinality', 'm-m' );
		$query->set( 'duplicatable', $duplicatable );
		$query->set( 'closurable', $closurable );
		$this->client->registerRelation( $query );
	}

	private function find_connections(
		string $relation,
		?ConnectionQuery $query = null
	): ConnectionCollection {
		return $this->client->getRelation( $relation )->findConnections( $query );
	}

	private function connection_ids( iterable $connections ): array
	{
		$ids = [];
		foreach ( $connections as $connection ) {
			$ids[] = $connection->id;
		}

		return $ids;
	}

	private function assert_connection_ids( array $expected, iterable $connections ): void
	{
		$actual = $this->connection_ids( $connections );
		sort( $expected, SORT_NUMERIC );
		sort( $actual, SORT_NUMERIC );
		self::assertSame( $expected, $actual );
	}

	private function meta_row_count( int $connection_id ): int
	{
		global $wpdb;

		$table = $wpdb->prefix . $this->client->getStorage()->get_meta_table();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE `connection_id` = %d",
				$connection_id
			)
		);
	}
}
