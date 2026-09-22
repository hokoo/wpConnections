<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Client;
use iTRON\wpConnections\Internal\DeletedPostRepairStatus;
use iTRON\wpConnections\Query\Connection as ConnectionQuery;
use iTRON\wpConnections\Query\Meta;

/**
 * DB-04-Q real WordPress deletion-flow fixture.
 */
final class DeletedPostRecoveryRealFlowTest extends WPConnectionsTestCase
{
    public function test_permanent_delete_fixture_observes_data_hooks_and_repair_state(): void
    {
        $query = new ConnectionQuery($this->page_ids[0], $this->post_ids[0]);
        $query->meta->add(new Meta('real-flow', 'permanent-delete'));
        $connection = $this->client->getRelation(RELATION_0_NAME)->createConnection($query);
        $attempts = [];
        $committed = [];
        $attempt_hook = static function (Client $client, $object_ids) use (&$attempts): void {
            $attempts[] = [ $client, $object_ids ];
        };
        $committed_hook = static function (Client $client, array $connection_ids) use (&$committed): void {
            $committed[] = [ $client, $connection_ids ];
        };
        add_action('wpConnections/storage/deleteByObjectID', $attempt_hook, 10, 2);
        add_action('wpConnections/storage/deletedByObjectID', $committed_hook, 10, 2);

        try {
            $deleted = wp_delete_post($this->post_ids[0], true);
        } finally {
            remove_action('wpConnections/storage/deleteByObjectID', $attempt_hook, 10);
            remove_action('wpConnections/storage/deletedByObjectID', $committed_hook, 10);
        }

        self::assertInstanceOf(\WP_Post::class, $deleted);
        self::assertNull(get_post($this->post_ids[0]));
        self::assertSame(0, $this->connection_count());
        self::assertSame(0, $this->meta_count());
        self::assertCount(1, $attempts);
        self::assertSame($this->client, $attempts[0][0]);
        self::assertSame($this->post_ids[0], $attempts[0][1]);
        self::assertCount(1, $committed);
        self::assertSame($this->client, $committed[0][0]);
        self::assertSame([ $connection->id ], $committed[0][1]);
        self::assertSame(
            [],
            $this->client->getDeletedPostRepairService()->listRepairs()->getItems()
        );
    }

    public function test_trash_only_fixture_does_not_run_permanent_delete_cascade(): void
    {
        $query = new ConnectionQuery($this->page_ids[0], $this->post_ids[0]);
        $query->meta->add(new Meta('real-flow', 'trash-only'));
        $connection = $this->client->getRelation(RELATION_0_NAME)->createConnection($query);
        $attempts = 0;
        $attempt_hook = static function (Client $client) use (&$attempts): void {
            unset($client);
            $attempts++;
        };
        add_action('wpConnections/storage/deleteByObjectID', $attempt_hook);

        try {
            $trashed = wp_trash_post($this->post_ids[0]);
        } finally {
            remove_action('wpConnections/storage/deleteByObjectID', $attempt_hook);
        }

        self::assertInstanceOf(\WP_Post::class, $trashed);
        self::assertSame('trash', get_post_status($this->post_ids[0]));
        self::assertSame(0, $attempts);
        self::assertSame(1, $this->connection_count());
        self::assertSame(1, $this->meta_count());
        self::assertTrue(
            $this->client->getRelation(RELATION_0_NAME)->hasConnectionID($connection->id)
        );
        self::assertSame(
            [],
            $this->client->getDeletedPostRepairService()->listRepairs(
                DeletedPostRepairStatus::RETRY_WAIT
            )->getItems()
        );
    }

    private function connection_count(): int
    {
        global $wpdb;
        $table = $wpdb->prefix . $this->client->getStorage()->get_connections_table();

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
    }

    private function meta_count(): int
    {
        global $wpdb;
        $table = $wpdb->prefix . $this->client->getStorage()->get_meta_table();

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
    }
}
