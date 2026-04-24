<?php

namespace iTRON\wpConnections\Tests;

use iTRON\wpConnections\Exceptions\RelationNotFound;
use iTRON\wpConnections\RelationCollection;
use PHPUnit\Framework\TestCase;

class RelationCollectionTest extends TestCase
{
    public function testGetThrowsRelationNotFoundWhenCollectionIsEmpty()
    {
        $collection = new RelationCollection();

        $this->expectException(RelationNotFound::class);
        $this->expectExceptionMessage('Relation not found: missing');

        $collection->get('missing');
    }
}
