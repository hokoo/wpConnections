<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\Tests;

use iTRON\wpConnections\Internal\DeletedPostRepairIdentity;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DeletedPostRepairIdentityTest extends TestCase
{
	private const OPERATION = 'delete_post_connections:v1';

	public function test_identity_exposes_canonical_values_and_a_stable_length_delimited_key(): void
	{
		$identity = new DeletedPostRepairIdentity( 1, 'wp_', 'client-a', self::OPERATION, 42 );

		self::assertSame( 1, $identity->getSiteId() );
		self::assertSame( 'wp_', $identity->getSitePrefix() );
		self::assertSame( 'client-a', $identity->getClientName() );
		self::assertSame( self::OPERATION, $identity->getOperation() );
		self::assertSame( 42, $identity->getPostId() );
		self::assertSame(
			'85c2a65f43dfea22c683e7c436f4d0e6dcc501c09f78e3c48f88de2959b61600',
			$identity->getKey()
		);
		self::assertSame(
			$identity->getKey(),
			( new DeletedPostRepairIdentity( 1, 'wp_', 'client-a', self::OPERATION, 42 ) )->getKey()
		);
	}

	/**
	 * @dataProvider changed_identity_field_provider
	 */
	public function test_each_domain_identity_field_changes_the_key(
		int $site_id,
		string $site_prefix,
		string $client_name,
		string $operation,
		int $post_id
	): void {
		$baseline = new DeletedPostRepairIdentity( 1, 'wp_', 'client-a', self::OPERATION, 42 );
		$changed  = new DeletedPostRepairIdentity( $site_id, $site_prefix, $client_name, $operation, $post_id );

		self::assertNotSame( $baseline->getKey(), $changed->getKey() );
	}

	public function changed_identity_field_provider(): array
	{
		return [
			'site ID'     => [ 2, 'wp_', 'client-a', self::OPERATION, 42 ],
			'site prefix' => [ 1, 'wp_2_', 'client-a', self::OPERATION, 42 ],
			'client name' => [ 1, 'wp_', 'client-b', self::OPERATION, 42 ],
			'operation'   => [ 1, 'wp_', 'client-a', 'delete_post_connections:v2', 42 ],
			'post ID'     => [ 1, 'wp_', 'client-a', self::OPERATION, 43 ],
		];
	}

	public function test_length_delimiting_prevents_ambiguous_field_concatenation(): void
	{
		$first  = new DeletedPostRepairIdentity( 1, 'ab', 'c', self::OPERATION, 42 );
		$second = new DeletedPostRepairIdentity( 1, 'a', 'bc', self::OPERATION, 42 );

		self::assertSame( 'abc', $first->getSitePrefix() . $first->getClientName() );
		self::assertSame( 'abc', $second->getSitePrefix() . $second->getClientName() );
		self::assertNotSame( $first->getKey(), $second->getKey() );
	}

	/**
	 * @dataProvider invalid_identity_provider
	 */
	public function test_invalid_or_noncanonical_values_are_rejected(
		int $site_id,
		string $site_prefix,
		string $client_name,
		string $operation,
		int $post_id
	): void {
		$this->expectException( InvalidArgumentException::class );

		new DeletedPostRepairIdentity( $site_id, $site_prefix, $client_name, $operation, $post_id );
	}

	public function invalid_identity_provider(): array
	{
		return [
			'zero site ID'              => [ 0, 'wp_', 'client-a', self::OPERATION, 42 ],
			'negative site ID'          => [ -1, 'wp_', 'client-a', self::OPERATION, 42 ],
			'empty site prefix'         => [ 1, '', 'client-a', self::OPERATION, 42 ],
			'unsafe site prefix'        => [ 1, 'wp-unsafe_', 'client-a', self::OPERATION, 42 ],
			'empty client name'         => [ 1, 'wp_', '', self::OPERATION, 42 ],
			'noncanonical client name'  => [ 1, 'wp_', 'Client A', self::OPERATION, 42 ],
			'empty operation'           => [ 1, 'wp_', 'client-a', '', 42 ],
			'unversioned operation'     => [ 1, 'wp_', 'client-a', 'delete_post_connections', 42 ],
			'unsafe operation'          => [ 1, 'wp_', 'client-a', 'delete_post_connections:v1;drop', 42 ],
			'zero post ID'              => [ 1, 'wp_', 'client-a', self::OPERATION, 0 ],
			'negative post ID'          => [ 1, 'wp_', 'client-a', self::OPERATION, -1 ],
		];
	}
}
