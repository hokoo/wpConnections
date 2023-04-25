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
}
