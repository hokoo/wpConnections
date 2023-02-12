<?php

namespace iTRON\wpConnections\Tests;

use iTRON\wpConnections\Meta;
use iTRON\wpConnections\MetaCollection;
use PHPUnit\Framework\TestCase;

class MetaCollectionTest extends TestCase {

	public function test_toArray() {
		$mc = new MetaCollection();
		$mc->add( new Meta( 'key1', 'value1' ) );
		$mc->add( new Meta( 'key1', 'value2' ) );
		$mc->add( new Meta( 'key2', 'value2' ) );
		$mc->add( new Meta( 'key3', 'value3' ) );

		$result = [
			'key1' => [ 'value1', 'value2' ],
			'key2' => [ 'value2' ],
			'key3' => [ 'value3' ],
		];

		self::assertEquals( $result, $mc->toArray() );
	}
}
