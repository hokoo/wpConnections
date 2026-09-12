<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Query\Connection;

class ClientRestApiTest extends WPConnectionsTestCase
{
	public function set_up()
	{
		parent::set_up();
		$this->set_up_rest_server();
	}

	public function tear_down()
	{
		try {
			$this->tear_down_rest_server();
		} finally {
			parent::tear_down();
		}
	}

	public function test_registers_all_route_patterns_and_methods(): void
	{
		$namespace_route = '/wp-connections/v1';
		$client_route = $this->get_rest_route();
		$relation_route = $client_route . '/relation/(?P<relation>[\w-]+)';
		$connection_route = $relation_route . '/(?P<connectionID>[\d]+)';
		$meta_route = $connection_route . '/meta';

		$routes = $this->rest_server->get_routes( 'wp-connections/v1' );

		self::assertCount( 5, $routes );
		self::assertArrayHasKey( $namespace_route, $routes );
		foreach ( [ $client_route, $relation_route, $connection_route, $meta_route ] as $custom_route ) {
			self::assertArrayHasKey( $custom_route, $routes );
		}

		self::assertSame(
			[ 'GET' ],
			$this->get_registered_methods( $routes[ $client_route ] )
		);
		self::assertSame(
			[ 'GET', 'POST' ],
			$this->get_registered_methods( $routes[ $relation_route ] )
		);
		self::assertSame(
			[
				'DELETE',
				'GET',
				'PATCH',
				'POST',
				'PUT',
			],
			$this->get_registered_methods( $routes[ $connection_route ] )
		);
		self::assertSame(
			[
				'DELETE',
				'PATCH',
				'POST',
				'PUT',
			],
			$this->get_registered_methods( $routes[ $meta_route ] )
		);
	}

	public function test_postman_inventory_is_registered_and_documents_editable_drift(): void
	{
		$routes = $this->rest_server->get_routes( 'wp-connections/v1' );
		$postman_requests = $this->get_postman_requests();

		self::assertCount( 11, $postman_requests );

		$postman_pairs = [];
		foreach ( $postman_requests as $postman_request ) {
			$pair = $postman_request['method'] . ' ' . $postman_request['path'];
			$postman_pairs [] = $pair;

			self::assertTrue(
				$this->routes_support_method( $routes, $postman_request['path'], $postman_request['method'] ),
				'Postman request is not registered: ' . $pair
			);
		}

		$namespace_requests = array_filter(
			$postman_requests,
			static function ( array $request ): bool {
				return '/wp-connections/v1' === $request['path'];
			}
		);
		$custom_requests = array_filter(
			$postman_requests,
			static function ( array $request ): bool {
				return '/wp-connections/v1' !== $request['path'];
			}
		);

		self::assertCount( 1, $namespace_requests, 'The namespace root is WordPress-generated.' );
		self::assertCount( 10, $custom_requests );

		$connection_path = $this->get_rest_route( '/relation/' . RELATION_0_NAME . '/1' );
		self::assertNotContains( 'PUT ' . $connection_path, $postman_pairs );
		self::assertNotContains( 'PATCH ' . $connection_path, $postman_pairs );
	}

	public function test_rejects_missing_required_args_before_handler(): void
	{
		$this->authenticate_as_administrator();

		$response = $this->dispatch_rest_request(
			'POST',
			$this->get_rest_route( '/relation/' . RELATION_0_NAME )
		);

		$this->assert_rest_error_response( 'rest_missing_callback_param', 400, $response );
		self::assertSame( [ 'from', 'to' ], $response->get_data()['data']['params'] );
		self::assertTrue( $this->client->getRelation( RELATION_0_NAME )->findConnections()->isEmpty() );
	}

	public function test_denies_unauthenticated_request(): void
	{
		$this->authenticate_as_anonymous();

		$response = $this->dispatch_rest_request( 'GET', $this->get_rest_route() );

		$this->assert_rest_error_response( 'rest_forbidden', 401, $response );
	}

	public function test_dispatches_authenticated_callback_and_serializes_response(): void
	{
		$query = new Connection( $this->page_ids[0], $this->post_ids[0] );
		$query->set( 'title', 'REST harness connection' );
		$query->set( 'order', 2 );

		$connection = $this->client->getRelation( RELATION_0_NAME )->createConnection( $query );
		$this->authenticate_as_administrator();

		$response = $this->dispatch_rest_request(
			'GET',
			$this->get_rest_route(
				'/relation/' . RELATION_0_NAME . '/' . $connection->id
			)
		);

		self::assertSame( 200, $response->get_status() );
		$serialized_data = $this->rest_server->response_to_data( $response, false );
		$expected_data = [
			'id'       => $connection->id,
			'title'    => 'REST harness connection',
			'relation' => RELATION_0_NAME,
			'from'     => $this->page_ids[0],
			'to'       => $this->post_ids[0],
			'order'    => 2,
			'meta'     => [],
		];

		self::assertSame(
			$expected_data,
			$serialized_data
		);
		self::assertSame( $expected_data, json_decode( wp_json_encode( $serialized_data ), true ) );
	}

	private function get_registered_methods( array $handlers ): array
	{
		$methods = [];

		foreach ( $handlers as $handler ) {
			if ( ! is_array( $handler ) || empty( $handler['methods'] ) || empty( $handler['callback'] ) ) {
				continue;
			}

			foreach ( array_keys( array_filter( $handler['methods'] ) ) as $method ) {
				$methods[] = $method;
			}
		}

		sort( $methods );

		return $methods;
	}

	private function get_postman_requests(): array
	{
		$postman_file = dirname( __DIR__, 4 ) . '/postman.json';
		$collection = json_decode(
			(string) file_get_contents( $postman_file ),
			true,
			512,
			JSON_THROW_ON_ERROR
		);

		return $this->collect_postman_requests( $collection['item'] ?? [] );
	}

	private function collect_postman_requests( array $items ): array
	{
		$requests = [];

		foreach ( $items as $item ) {
			if ( isset( $item['request']['method'], $item['request']['url']['path'] ) ) {
				$path_parts = $item['request']['url']['path'];
				if ( 'wp-json' === ( $path_parts[0] ?? null ) ) {
					array_shift( $path_parts );
				}

				$path = '/' . implode( '/', $path_parts );
				$requests [] = [
					'method' => strtoupper( $item['request']['method'] ),
					'path'   => strtr(
						$path,
						[
							'{{client}}'   => $this->client->getName(),
							'{{relation}}' => RELATION_0_NAME,
						]
					),
				];
			}

			if ( isset( $item['item'] ) && is_array( $item['item'] ) ) {
				$requests = array_merge( $requests, $this->collect_postman_requests( $item['item'] ) );
			}
		}

		return $requests;
	}

	private function routes_support_method( array $routes, string $path, string $method ): bool
	{
		foreach ( $routes as $route_pattern => $handlers ) {
			if ( 1 !== preg_match( '#^' . $route_pattern . '$#', $path ) ) {
				continue;
			}

			foreach ( $handlers as $handler ) {
				if ( is_array( $handler ) && ! empty( $handler['methods'][ $method ] ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
