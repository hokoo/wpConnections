<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Client;
use iTRON\wpConnections\Connection;
use iTRON\wpConnections\Helpers\Database;
use iTRON\wpConnections\Internal\DeletedPostRepairStatus;
use iTRON\wpConnections\Query\Connection as ConnectionQuery;
use iTRON\wpConnections\Query\Meta;
use iTRON\wpConnections\Query\Relation as RelationQuery;
use iTRON\wpConnections\WPStorage;

/**
 * DB-04-Q real WordPress deletion-flow fixture.
 */
final class DeletedPostRecoveryRealFlowTest extends WPConnectionsTestCase
{
    private const POST_GRAPH_RELATION = 'real-flow-post-graph';
    private const ATTACHMENT_RELATION = 'real-flow-attachment';

    /** @var Client[] */
    private array $additionalClients = [];

    public function tear_down()
    {
        try {
            foreach ($this->additionalClients as $client) {
                $client->dispose();
                $this->drop_client_artifacts($client);
            }
        } finally {
            parent::tear_down();
        }
    }

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

    public function test_permanent_delete_cascades_all_endpoint_shapes_and_preserves_unrelated_rows(): void
    {
        $this->register_relation(
            $this->client,
            self::POST_GRAPH_RELATION,
            'post',
            'post'
        );
        $unrelated_post_id = self::factory()->post->create([ 'post_type' => 'post' ]);
        $deleted_post_id = $this->post_ids[0];

        $deleted_connections = [
            $this->create_connection(
                $this->client,
                RELATION_0_NAME,
                $this->page_ids[0],
                $deleted_post_id,
                'incoming-primary'
            ),
            $this->create_connection(
                $this->client,
                RELATION_1_NAME,
                $this->page_ids[0],
                $deleted_post_id,
                'incoming-second-relation'
            ),
            $this->create_connection(
                $this->client,
                self::POST_GRAPH_RELATION,
                $deleted_post_id,
                $this->post_ids[1],
                'outgoing'
            ),
            $this->create_connection(
                $this->client,
                self::POST_GRAPH_RELATION,
                $deleted_post_id,
                $deleted_post_id,
                'self'
            ),
        ];
        $unrelated = $this->create_connection(
            $this->client,
            self::POST_GRAPH_RELATION,
            $this->post_ids[1],
            $unrelated_post_id,
            'unrelated'
        );
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
            self::assertSame(5, $this->connection_count());
            self::assertSame(5, $this->meta_count());
            $deleted = wp_delete_post($deleted_post_id, true);
        } finally {
            remove_action('wpConnections/storage/deleteByObjectID', $attempt_hook, 10);
            remove_action('wpConnections/storage/deletedByObjectID', $committed_hook, 10);
        }

        self::assertInstanceOf(\WP_Post::class, $deleted);
        self::assertNull(get_post($deleted_post_id));
        self::assertSame(1, $this->connection_count());
        self::assertSame(1, $this->meta_count());
        self::assertTrue(
            $this->client->getRelation(self::POST_GRAPH_RELATION)->hasConnectionID($unrelated->id)
        );
        foreach ($deleted_connections as $connection) {
            self::assertFalse(
                $this->client->getRelation($connection->relation)->hasConnectionID($connection->id)
            );
        }
        self::assertCount(1, $attempts);
        self::assertSame([ $this->client, $deleted_post_id ], $attempts[0]);
        self::assertCount(1, $committed);
        self::assertSame($this->client, $committed[0][0]);
        $expected_ids = array_map(
            static fn (Connection $connection): int => $connection->id,
            $deleted_connections
        );
        sort($expected_ids);
        sort($committed[0][1]);
        self::assertSame($expected_ids, $committed[0][1]);
        self::assertSame(
            [],
            $this->client->getDeletedPostRepairService()->listRepairs()->getItems()
        );
    }

    public function test_permanent_delete_isolates_multiple_clients_and_their_success_hooks(): void
    {
        $second_client = $this->new_additional_client('real-flow-secondary');
        $this->register_relation($second_client, RELATION_0_NAME, 'page', 'post');
        $deleted_post_id = $this->post_ids[0];
        $main_match = $this->create_connection(
            $this->client,
            RELATION_0_NAME,
            $this->page_ids[0],
            $deleted_post_id,
            'main-match'
        );
        $main_unrelated = $this->create_connection(
            $this->client,
            RELATION_0_NAME,
            $this->page_ids[0],
            $this->post_ids[1],
            'main-unrelated'
        );
        $second_match = $this->create_connection(
            $second_client,
            RELATION_0_NAME,
            $this->page_ids[0],
            $deleted_post_id,
            'second-match'
        );
        $second_unrelated = $this->create_connection(
            $second_client,
            RELATION_0_NAME,
            $this->page_ids[0],
            $this->post_ids[1],
            'second-unrelated'
        );
        $attempts = [];
        $committed = [];
        $attempt_hook = static function (Client $client, $object_ids) use (&$attempts): void {
            $attempts[$client->getName()][] = $object_ids;
        };
        $committed_hook = static function (Client $client, array $connection_ids) use (&$committed): void {
            $committed[$client->getName()][] = $connection_ids;
        };
        add_action('wpConnections/storage/deleteByObjectID', $attempt_hook, 10, 2);
        add_action('wpConnections/storage/deletedByObjectID', $committed_hook, 10, 2);

        try {
            $deleted = wp_delete_post($deleted_post_id, true);
        } finally {
            remove_action('wpConnections/storage/deleteByObjectID', $attempt_hook, 10);
            remove_action('wpConnections/storage/deletedByObjectID', $committed_hook, 10);
        }

        self::assertInstanceOf(\WP_Post::class, $deleted);
        self::assertSame(1, $this->connection_count($this->client));
        self::assertSame(1, $this->meta_count($this->client));
        self::assertSame(1, $this->connection_count($second_client));
        self::assertSame(1, $this->meta_count($second_client));
        self::assertFalse(
            $this->client->getRelation(RELATION_0_NAME)->hasConnectionID($main_match->id)
        );
        self::assertTrue(
            $this->client->getRelation(RELATION_0_NAME)->hasConnectionID($main_unrelated->id)
        );
        self::assertFalse(
            $second_client->getRelation(RELATION_0_NAME)->hasConnectionID($second_match->id)
        );
        self::assertTrue(
            $second_client->getRelation(RELATION_0_NAME)->hasConnectionID($second_unrelated->id)
        );
        self::assertCount(2, $attempts);
        self::assertSame([ $deleted_post_id ], $attempts[$this->client->getName()] ?? null);
        self::assertSame([ $deleted_post_id ], $attempts[$second_client->getName()] ?? null);
        self::assertCount(2, $committed);
        self::assertSame([ [ $main_match->id ] ], $committed[$this->client->getName()] ?? null);
        self::assertSame([ [ $second_match->id ] ], $committed[$second_client->getName()] ?? null);
        self::assertSame(
            [],
            $this->client->getDeletedPostRepairService()->listRepairs()->getItems()
        );
        self::assertSame(
            [],
            $second_client->getDeletedPostRepairService()->listRepairs()->getItems()
        );
    }

    public function test_permanent_attachment_delete_uses_the_same_cascade_contract(): void
    {
        $this->register_relation(
            $this->client,
            self::ATTACHMENT_RELATION,
            'page',
            'attachment'
        );
        $attachment_id = wp_insert_attachment(
            [
                'post_title' => 'Real-flow attachment',
                'post_status' => 'inherit',
                'post_mime_type' => 'image/png',
            ],
            ''
        );
        self::assertIsInt($attachment_id);
        $connection = $this->create_connection(
            $this->client,
            self::ATTACHMENT_RELATION,
            $this->page_ids[0],
            $attachment_id,
            'attachment'
        );
        $attempts = 0;
        $committed = [];
        $attempt_hook = static function (Client $client) use (&$attempts): void {
            unset($client);
            $attempts++;
        };
        $committed_hook = static function (Client $client, array $connection_ids) use (&$committed): void {
            unset($client);
            $committed[] = $connection_ids;
        };
        add_action('wpConnections/storage/deleteByObjectID', $attempt_hook);
        add_action('wpConnections/storage/deletedByObjectID', $committed_hook, 10, 2);

        try {
            $deleted = wp_delete_post($attachment_id, true);
        } finally {
            remove_action('wpConnections/storage/deleteByObjectID', $attempt_hook);
            remove_action('wpConnections/storage/deletedByObjectID', $committed_hook, 10);
        }

        self::assertInstanceOf(\WP_Post::class, $deleted);
        self::assertNull(get_post($attachment_id));
        self::assertSame(0, $this->connection_count());
        self::assertSame(0, $this->meta_count());
        self::assertSame(1, $attempts);
        self::assertSame([ [ $connection->id ] ], $committed);
        self::assertSame(
            [],
            $this->client->getDeletedPostRepairService()->listRepairs()->getItems()
        );
    }

    private function create_connection(
        Client $client,
        string $relation,
        int $from,
        int $to,
        string $label
    ): Connection {
        $query = new ConnectionQuery($from, $to);
        $query->meta->add(new Meta('real-flow', $label));

        return $client->getRelation($relation)->createConnection($query);
    }

    private function register_relation(
        Client $client,
        string $name,
        string $from,
        string $to
    ): void {
        $relation = new RelationQuery();
        $relation->set('name', $name);
        $relation->set('from', $from);
        $relation->set('to', $to);
        $relation->set('cardinality', 'm-m');
        $relation->set('closurable', true);
        $client->registerRelation($relation);
    }

    private function new_additional_client(string $name): Client
    {
        $client = new Client($name);
        $this->additionalClients[] = $client;

        return $client;
    }

    private function connection_count(?Client $client = null): int
    {
        global $wpdb;
        $client = $client ?? $this->client;
        $table = $wpdb->prefix . $client->getStorage()->get_connections_table();

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
    }

    private function meta_count(?Client $client = null): int
    {
        global $wpdb;
        $client = $client ?? $this->client;
        $table = $wpdb->prefix . $client->getStorage()->get_meta_table();

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
    }

    private function drop_client_artifacts(Client $client): void
    {
        global $wpdb;

        $postfix = Database::normalize_table_name($client->getName());
        $table_keys = [
            WPStorage::META_TABLE_PREFIX . $postfix,
            WPStorage::CONNECTIONS_TABLE_PREFIX . $postfix,
        ];
        foreach ($table_keys as $table_key) {
            $wpdb->query("DROP TEMPORARY TABLE IF EXISTS `{$wpdb->prefix}{$table_key}`");
            $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}{$table_key}`");
            unset($wpdb->{$table_key});
        }

        $ownership_option = 'wpconnections_storage_owner_' . hash('sha256', $postfix);
        delete_option($ownership_option);
        wp_cache_delete($ownership_option, 'options');
        wp_cache_delete('notoptions', 'options');
        $wpdb->tables = array_values(
            array_filter(
                $wpdb->tables,
                static fn (string $table_key): bool => ! in_array($table_key, $table_keys, true)
            )
        );
    }
}
