<?php

namespace iTRON\wpConnections\Tests;

use iTRON\wpConnections\Meta;
use iTRON\wpConnections\MetaCollection;
use PHPUnit\Framework\TestCase;

class MetaCollectionTest extends TestCase {

	public function testToArray() {
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

	public function testFromArray() {
		$mc = new MetaCollection();
		$dataArray = [
			'key1' => [ 'value1', 'value2' ],
			'key2' => [ 'value2' ],
			'key3' => [ 'value3' ],
		];
		$mc->fromArray( $dataArray );

		$mcExpected = new MetaCollection();
		$mcExpected->add( new Meta( 'key1', 'value1' ) );
		$mcExpected->add( new Meta( 'key1', 'value2' ) );
		$mcExpected->add( new Meta( 'key2', 'value2' ) );
		$mcExpected->add( new Meta( 'key3', 'value3' ) );

		self::assertEquals( $mc, $mcExpected );
	}
}
