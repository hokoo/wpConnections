<?php

namespace iTRON\wpConnections\Tests;

use iTRON\wpConnections\Meta;
use iTRON\wpConnections\MetaCollection;
use PHPUnit\Framework\TestCase;

class MetaCollectionTest extends TestCase
{
    public function testToArray()
    {
        $mc = new MetaCollection();
        $mc->add(new Meta('key1', 'value1'));
        $mc->add(new Meta('key1', 'value2'));
        $mc->add(new Meta('key2', 'value2'));
        $mc->add(new Meta('key3', 'value3'));
        $mc->add(new Meta('key4'));

        $mcExpected = [
            'key1' => [ 'value1', 'value2' ],
            'key2' => [ 'value2' ],
            'key3' => [ 'value3' ],
            'key4' => [ null ],
        ];

        self::assertEquals($mcExpected, $mc->toArray());
    }

    public function testFromArray()
    {
        $dataArrayType0 = [
            [
                'key'   => 'key0',
                'value' => 'value0',
            ],
            [
                'key'   => 'key0',
                'value' => 'value1',
            ],
            [
                'key'   => 'key1',
                'value' => 'value1',
            ],
            [
                'key'   => 'key2',
            ],
        ];
        $resultType0 = new MetaCollection();
        $resultType0->fromArray($dataArrayType0);

        $mcExpectedType0 = new MetaCollection();
        $mcExpectedType0->add(new Meta('key0', 'value0'));
        $mcExpectedType0->add(new Meta('key0', 'value1'));
        $mcExpectedType0->add(new Meta('key1', 'value1'));
        $mcExpectedType0->add(new Meta('key2'));

        self::assertEquals($mcExpectedType0, $resultType0);

        $dataArrayType1 = [
            'key1' => [ 'value1', 'value2' ],
            'key2' => [ 'value2' ],
            'key3' => [ 'value3' ],
            'key4' => [],
            'key5' => null,
            'key6' => '',
        ];
        $resultType1 = new MetaCollection();
        $resultType1->fromArray($dataArrayType1);

        $mcExpectedType1 = new MetaCollection();
        $mcExpectedType1->add(new Meta('key1', 'value1'));
        $mcExpectedType1->add(new Meta('key1', 'value2'));
        $mcExpectedType1->add(new Meta('key2', 'value2'));
        $mcExpectedType1->add(new Meta('key3', 'value3'));
        $mcExpectedType1->add(new Meta('key4'));
        $mcExpectedType1->add(new Meta('key5', null));
        $mcExpectedType1->add(new Meta('key6', ''));

        self::assertEquals($mcExpectedType1, $resultType1);
    }
}
