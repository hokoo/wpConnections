<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\Tests;

use iTRON\wpConnections\Connection;
use iTRON\wpConnections\EndpointTarget;
use iTRON\wpConnections\Query\Connection as ConnectionQuery;
use PHPUnit\Framework\TestCase;

class EndpointTargetTest extends TestCase
{
    public function test_absolute_and_opposite_roles_are_explicit(): void
    {
        $outbound = new Connection(new ConnectionQuery(4, 10));
        $inbound = new Connection(new ConnectionQuery(10, 4));
        $self = new Connection(new ConnectionQuery(4, 4));

        self::assertSame([ 'from' ], EndpointTarget::from()->roles($outbound));
        self::assertSame([ 'to' ], EndpointTarget::to()->roles($outbound));
        self::assertSame([ 'from', 'to' ], EndpointTarget::both()->roles($self));
        self::assertSame([ 'to' ], EndpointTarget::opposite('from', 4)->roles($outbound));
        self::assertSame([ 'from' ], EndpointTarget::opposite('to', 4)->roles($inbound));
        self::assertSame([ 'to' ], EndpointTarget::opposite('both', 4)->roles($outbound));
        self::assertSame([ 'from' ], EndpointTarget::opposite('both', 4)->roles($inbound));
        self::assertSame([], EndpointTarget::opposite('both', 4)->roles($self));
        self::assertSame([], EndpointTarget::opposite('from', 4)->roles($self));
        self::assertSame([], EndpointTarget::opposite('to', 4)->roles($self));
        self::assertSame([], EndpointTarget::opposite('from', 5)->roles($outbound));
    }

    public function test_opposite_rejects_invalid_selector_or_anchor(): void
    {
        foreach ([ [ 'from', 0 ], [ 'both', -1 ], [ 'from,to', 4 ], [ '', 4 ] ] as $case) {
            try {
                EndpointTarget::opposite($case[0], $case[1]);
                self::fail('Invalid opposite selector was accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }
}
