<?php

namespace iTRON\wpConnections\Tests;

use iTRON\wpConnections\Query\Connection;
use iTRON\wpConnections\Query\Meta;
use PHPUnit\Framework\TestCase;

class QueryMetaMaterializationTest extends TestCase
{
    public function test_query_connection_meta_from_array_materializes_one_pair(): void
    {
        $connection = new Connection();

        $connection->meta->fromArray([ 'query-key' => 'query-value' ]);

        self::assertCount(1, $connection->meta);
        self::assertInstanceOf(Meta::class, $connection->meta->first());
        self::assertSame('query-key', $connection->meta->first()->getKey());
        self::assertSame('query-value', $connection->meta->first()->getValue());
        self::assertSame([ 'query-key' => [ 'query-value' ] ], $connection->meta->toArray());
    }
}
