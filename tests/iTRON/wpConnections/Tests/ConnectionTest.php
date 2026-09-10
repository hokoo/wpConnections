<?php

namespace iTRON\wpConnections\Tests;

use iTRON\wpConnections\Connection;
use PHPUnit\Framework\TestCase;

class ConnectionTest extends TestCase
{
    public function testCreateConnection()
    {
        $connection = new Connection(new \iTRON\wpConnections\Query\Connection(1, 2));
        self::assertEquals(1, $connection->from);
        self::assertEquals(2, $connection->to);
    }

    public function testLoadRemainsNoOpDuringDeprecationWindow(): void
    {
        $connection = new Connection(new \iTRON\wpConnections\Query\Connection(1, 2));

        self::assertNull($connection->load());
        self::assertSame(1, $connection->from);
        self::assertSame(2, $connection->to);
    }

    public function testLoadHasDocumentedDeprecationContract(): void
    {
        $reflection = new \ReflectionMethod(Connection::class, 'load');
        $docComment = $reflection->getDocComment();

        self::assertIsString($docComment);
        self::assertStringContainsString('@deprecated 1.x', $docComment);
        self::assertStringContainsString('2.0.0', $docComment);
        self::assertStringContainsString('Relation::findConnections()', $docComment);
    }
}
