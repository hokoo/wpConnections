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

	public function test_openapi_inventory_matches_registered_custom_routes(): void
	{
		$routes = $this->rest_server->get_routes( 'wp-connections/v1' );
		$spec = json_decode(
			(string) file_get_contents( dirname( __DIR__, 4 ) . '/docs/openapi.json' ),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		self::assertSame( '3.0.3', $spec['openapi'] );

		$documented = [];
		foreach ( $spec['paths'] as $path => $operations ) {
			foreach ( [ 'get', 'post', 'put', 'patch', 'delete' ] as $method ) {
				if ( isset( $operations[ $method ] ) ) {
					$documented[] = strtoupper( $method ) . ' ' . $path;
				}
			}
		}

		$registered = [];
		foreach ( $routes as $pattern => $handlers ) {
			if ( '/wp-connections/v1' === $pattern ) {
				continue; // WordPress-generated namespace discovery root.
			}
			$path = str_replace( $this->client->getName(), '{client}', $pattern );
			$path = preg_replace( '/\(\?P<([A-Za-z_][A-Za-z0-9_]*)>[^)]*\)/', '{$1}', $path );
			self::assertIsString( $path );
			foreach ( $handlers as $handler ) {
				if ( ! is_array( $handler ) || empty( $handler['callback'] ) ) {
					continue;
				}
				foreach ( array_keys( array_filter( $handler['methods'] ?? [] ) ) as $method ) {
					$registered[] = $method . ' ' . $path;
				}
			}
		}

		sort( $documented );
		sort( $registered );
		self::assertSame( $registered, $documented, 'OpenAPI path/method inventory differs from live custom routes.' );
		self::assertCount( 12, $documented );
		self::assertSame( [ 'code', 'message', 'data' ], $spec['components']['schemas']['DomainError']['required'] );
		self::assertSame( [ 'status', 'domain_code' ], $spec['components']['schemas']['DomainError']['properties']['data']['required'] );
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

}
