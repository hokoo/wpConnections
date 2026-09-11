<?php

namespace iTRON\wpConnections\Tests;

use iTRON\wpConnections\Query\Connection;
use PHPUnit\Framework\TestCase;

class ConnectionQueryPresenceTest extends TestCase
{
    public function test_omitted_endpoints_keep_legacy_direct_and_helper_reads(): void
    {
        $query = new Connection();

        foreach ([ 'from', 'to', 'both' ] as $field) {
            self::assertSame(0, $query->{$field});
            self::assertSame(0, $query->get($field));
            self::assertFalse($query->isProvided($field));
            self::assertTrue(isset($query->{$field}));
            self::assertTrue(property_exists($query, $field));
        }

        self::assertFalse($query->exists_from());
        self::assertFalse($query->exists_to());
        self::assertFalse($query->exists_both());
    }

    public function test_direct_nonzero_and_same_value_zero_writes_are_tracked(): void
    {
        $query       = new Connection();
        $query->from = 11;
        $query->to   = 22;
        $query->both = 0;

        self::assertSame(11, $query->from);
        self::assertSame(22, $query->to);
        self::assertSame(0, $query->both);
        self::assertTrue($query->isProvided('from'));
        self::assertTrue($query->isProvided('to'));
        self::assertTrue($query->isProvided('both'));
    }

    public function test_constructor_and_setter_track_explicit_zero(): void
    {
        $constructed = new Connection(0, 0, 0);

        self::assertTrue($constructed->isProvided('from'));
        self::assertTrue($constructed->isProvided('to'));
        self::assertTrue($constructed->isProvided('both'));

        $set = new Connection();
        $set->set('from', 0)->set('to', 0)->set('both', 0);

        self::assertTrue($set->isProvided('from'));
        self::assertTrue($set->isProvided('to'));
        self::assertTrue($set->isProvided('both'));
    }

    public function test_domain_array_conversion_materializes_legacy_zero_defaults(): void
    {
        $query = new Connection();
        $query->set('title', null);
        $query->set('order', 0);

        $array = $query->toArray();

        self::assertSame(0, $array['from']);
        self::assertSame(0, $array['to']);
    }

    public function test_raw_introspection_exposes_presence_instead_of_materialized_defaults(): void
    {
        $omitted = new Connection();

        self::assertArrayNotHasKey('from', get_object_vars($omitted));
        self::assertArrayNotHasKey('to', get_object_vars($omitted));
        self::assertArrayNotHasKey('both', get_object_vars($omitted));
        self::assertArrayNotHasKey('from', (array) json_decode(json_encode($omitted), true));

        $omitted->from = 0;

        self::assertArrayHasKey('from', get_object_vars($omitted));
        self::assertSame(0, get_object_vars($omitted)['from']);
        self::assertSame(0, json_decode(json_encode($omitted), true)['from']);
    }
}
