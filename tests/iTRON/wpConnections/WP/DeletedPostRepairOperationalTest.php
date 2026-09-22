<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Client;
use iTRON\wpConnections\Connection;
use iTRON\wpConnections\Internal\DeletedPostRepairRuntime;
use iTRON\wpConnections\Internal\DeletedPostRepairStatus;
use iTRON\wpConnections\Internal\WordPressDeletedPostRepairScheduler;
use iTRON\wpConnections\Query\Connection as ConnectionQuery;
use iTRON\wpConnections\Query\Meta;
use RuntimeException;

/**
 * Executable operator/degraded-cron scenarios for the public repair service.
 */
final class DeletedPostRepairOperationalTest extends WPConnectionsTestCase
{
    private const REPAIR_TABLE_BASENAME = 'wpconnections_repair';
    private const OWNERSHIP_OPTION = 'wpconnections_repair_schema_owner';

    public function set_up()
    {
        parent::set_up();
        wp_clear_scheduled_hook(WordPressDeletedPostRepairScheduler::EVENT_HOOK);
    }

    public function tear_down()
    {
        try {
            wp_clear_scheduled_hook(WordPressDeletedPostRepairScheduler::EVENT_HOOK);
        } finally {
            parent::tear_down();
        }
    }

    public function test_disabled_cron_keeps_real_failure_inspectable_and_manually_retryable(): void
    {
        self::assertTrue(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON);
        self::assertFalse(
            ( new WordPressDeletedPostRepairScheduler() )->isAutomaticDispatchAvailable()
        );

        $connection = $this->create_connection(0, 'disabled-cron-single');
        $this->delete_with_cleanup_failure(0, 'sensitive disabled-cron failure detail');
        $service = $this->client->getDeletedPostRepairService();
        $page = $service->listRepairs(DeletedPostRepairStatus::RETRY_WAIT);

        self::assertCount(1, $page->getItems());
        self::assertNull($page->getNextAfterKey());
        $listed = $page->getItems()[0];
        $inspected = $service->getRepair($listed->getRepairKey());
        self::assertNotNull($inspected);
        self::assertSame($this->post_ids[0], $inspected->getPostId());
        self::assertSame(DeletedPostRepairStatus::RETRY_WAIT, $inspected->getStatus());
        self::assertSame('cleanup', $inspected->getFailureCategory());
        self::assertSame(RuntimeException::class, $inspected->getFailureClass());
        self::assertSame('[diagnostic details redacted]', $inspected->getFailureSummary());
        self::assertStringNotContainsString(
            'sensitive disabled-cron',
            (string) $inspected->getFailureSummary()
        );

        $event = wp_get_scheduled_event(WordPressDeletedPostRepairScheduler::EVENT_HOOK, []);
        self::assertIsObject($event);
        self::assertSame(WordPressDeletedPostRepairScheduler::EVENT_HOOK, $event->hook);
        self::assertSame([], $event->args);
        self::assertGreaterThan(0, $event->timestamp);

        $result = $service->retryRepair($inspected->getRepairKey());

        self::assertSame('resolved', $result->getOutcome());
        self::assertTrue($result->wasCleanupAttempted());
        self::assertNotNull($result->getRepair());
        self::assertSame(DeletedPostRepairStatus::RESOLVED, $result->getRepair()->getStatus());
        self::assertFalse($this->client->getRelation(RELATION_0_NAME)->hasConnectionID($connection->id));
        self::assertSame(0, $this->connection_count());
        self::assertSame(0, $this->meta_count());
    }

    public function test_due_batch_and_direct_runner_expose_exhaustion_for_manual_attention(): void
    {
        self::assertTrue(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON);

        $this->create_connection(0, 'disabled-cron-batch');
        $this->create_connection(1, 'disabled-cron-exhaustion');
        $this->delete_with_cleanup_failure(0, 'sensitive due-batch failure detail');
        $this->delete_with_cleanup_failure(1, 'sensitive exhaustion failure detail');
        $service = $this->client->getDeletedPostRepairService();
        $repairs = $service->listRepairs(DeletedPostRepairStatus::RETRY_WAIT)->getItems();
        self::assertCount(2, $repairs);

        $by_post_id = [];
        foreach ($repairs as $repair) {
            $by_post_id[ $repair->getPostId() ] = $repair;
        }
        $batch_repair = $by_post_id[ $this->post_ids[0] ];
        $exhausted_repair = $by_post_id[ $this->post_ids[1] ];
        $this->make_repair_due($batch_repair->getRepairKey());
        $this->set_repair_attempt_count($exhausted_repair->getRepairKey(), 9);

        $attempted_post_ids = [];
        $observe_attempt = static function (Client $client, $post_id) use (&$attempted_post_ids): void {
            unset($client);
            $attempted_post_ids[] = $post_id;
        };
        add_action('wpConnections/storage/deleteByObjectID', $observe_attempt, 10, 2);
        try {
            $batch = $service->retryDueRepairs(1, 10);
            self::assertSame('complete', $batch->getStopReason());
            self::assertFalse($batch->hasMoreDue());
            self::assertCount(1, $batch->getResults());
            self::assertSame('resolved', $batch->getResults()[0]->getOutcome());
            self::assertSame($batch_repair->getRepairKey(), $batch->getResults()[0]->getRepairKey());
            self::assertSame([ $this->post_ids[0] ], $attempted_post_ids);

            $this->make_repair_due($exhausted_repair->getRepairKey());
            $stored_event = wp_get_scheduled_event(
                WordPressDeletedPostRepairScheduler::EVENT_HOOK,
                []
            );
            self::assertIsObject($stored_event);

            // Invoke the registered runner directly; the test bootstrap performs no HTTP cron loopback.
            do_action(WordPressDeletedPostRepairScheduler::EVENT_HOOK);

            $attention = $service->getRepair($exhausted_repair->getRepairKey());
            self::assertNotNull($attention);
            self::assertSame(DeletedPostRepairStatus::NEEDS_ATTENTION, $attention->getStatus());
            self::assertSame(9, $attention->getAttemptCount());
            self::assertSame('[diagnostic details redacted]', $attention->getFailureSummary());
            self::assertSame([ $this->post_ids[0] ], $attempted_post_ids);

            $no_due_work = $service->retryDueRepairs(1, 10);
            self::assertSame('complete', $no_due_work->getStopReason());
            self::assertSame([], $no_due_work->getResults());

            $manual = $service->retryRepair($exhausted_repair->getRepairKey());
            self::assertSame('resolved', $manual->getOutcome());
            self::assertTrue($manual->wasCleanupAttempted());
            self::assertSame(
                [ $this->post_ids[0], $this->post_ids[1] ],
                $attempted_post_ids
            );
        } finally {
            remove_action('wpConnections/storage/deleteByObjectID', $observe_attempt, 10);
        }

        self::assertSame(0, $this->connection_count());
        self::assertSame(0, $this->meta_count());
    }

    public function test_unresolved_work_survives_client_disposal_and_runtime_reconstruction(): void
    {
        $this->create_connection(0, 'preserved-repair');
        $this->create_connection(1, 'disabled-delivery');
        $this->delete_with_cleanup_failure(0, 'preservation rehearsal failure');
        $service = $this->client->getDeletedPostRepairService();
        $inventory = $service->listRepairs(DeletedPostRepairStatus::RETRY_WAIT);
        self::assertCount(1, $inventory->getItems());
        $key = $inventory->getItems()[0]->getRepairKey();
        $row_before = $this->repair_row($key);
        $owner_before = $this->repair_owner();
        self::assertSame(2, $this->connection_count());
        self::assertSame(2, $this->meta_count());

        $this->client->disablePostDeletionCleanup();
        self::assertInstanceOf(\WP_Post::class, wp_delete_post($this->post_ids[1], true));
        self::assertCount(1, $service->listRepairs()->getItems());
        $this->client->dispose();
        do_action(WordPressDeletedPostRepairScheduler::EVENT_HOOK);
        self::assertSame($row_before, $this->repair_row($key));
        self::assertSame($owner_before, $this->repair_owner());
        self::assertSame(2, $this->connection_count());
        self::assertSame(2, $this->meta_count());

        // Model the process-local runtime boundary; do not reset any persistent state.
        DeletedPostRepairRuntime::instance()->resetForTests();
        self::assertSame($row_before, $this->repair_row($key));
        self::assertSame($owner_before, $this->repair_owner());
        $disable = static function (Client $client): void {
            $client->disablePostDeletionCleanup();
        };
        $init_hook = 'wpConnections/client/' . $this->client->getName() . '/inited';
        add_action($init_hook, $disable);
        try {
            $this->client = new Client(CLIENT_NAME);
        } finally {
            remove_action($init_hook, $disable);
        }

        $fresh_service = $this->client->getDeletedPostRepairService();
        self::assertNotSame($service, $fresh_service);
        self::assertSame($row_before, $this->repair_row($key));
        self::assertSame($owner_before, $this->repair_owner());
        $preserved = $fresh_service->getRepair($key);
        self::assertNotNull($preserved);
        self::assertSame(DeletedPostRepairStatus::RETRY_WAIT, $preserved->getStatus());
        self::assertSame($key, $preserved->getRepairKey());
        self::assertSame('[diagnostic details redacted]', $preserved->getFailureSummary());

        $result = $fresh_service->retryRepair($key);
        self::assertSame('resolved', $result->getOutcome());
        self::assertTrue($result->wasCleanupAttempted());
        self::assertSame(DeletedPostRepairStatus::RESOLVED, $this->repair_row($key)['status']);
        self::assertSame($owner_before, $this->repair_owner());
        // The post deleted while delivery was disabled remains outside this repair identity.
        self::assertSame(1, $this->connection_count());
        self::assertSame(1, $this->meta_count());
    }

    private function repair_row(string $key): array
    {
        global $wpdb;

        $table = $wpdb->prefix . self::REPAIR_TABLE_BASENAME;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `{$table}` WHERE `repair_key` = %s", $key),
            ARRAY_A
        );
        self::assertIsArray($row);

        return $row;
    }

    private function repair_owner(): string
    {
        global $wpdb;

        // Read the database rather than an option-cache copy left by the old Client.
        $owner = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT `option_value` FROM `{$wpdb->options}` WHERE `option_name` = %s",
                self::OWNERSHIP_OPTION
            )
        );
        self::assertIsString($owner);

        return $owner;
    }

    private function create_connection(int $post_index, string $label): Connection
    {
        $query = new ConnectionQuery($this->page_ids[0], $this->post_ids[ $post_index ]);
        $query->meta->add(new Meta('operator-flow', $label));

        return $this->client->getRelation(RELATION_0_NAME)->createConnection($query);
    }

    private function delete_with_cleanup_failure(int $post_index, string $sensitive_message): void
    {
        $failure = static function () use ($sensitive_message): void {
            throw new RuntimeException($sensitive_message);
        };
        add_action('wpConnections/storage/deleteByObjectID', $failure);
        try {
            self::assertInstanceOf(
                \WP_Post::class,
                wp_delete_post($this->post_ids[ $post_index ], true)
            );
        } finally {
            remove_action('wpConnections/storage/deleteByObjectID', $failure);
        }
    }

    private function make_repair_due(string $repair_key): void
    {
        global $wpdb;

        $table = $wpdb->prefix . self::REPAIR_TABLE_BASENAME;
        self::assertSame(
            1,
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE `{$table}` SET `next_attempt_at` = UTC_TIMESTAMP(), " .
                    '`updated_at` = UTC_TIMESTAMP() WHERE `repair_key` = %s',
                    $repair_key
                )
            )
        );
    }

    private function set_repair_attempt_count(string $repair_key, int $attempt_count): void
    {
        global $wpdb;

        $table = $wpdb->prefix . self::REPAIR_TABLE_BASENAME;
        self::assertSame(
            1,
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE `{$table}` SET `attempt_count` = %d, " .
                    '`updated_at` = UTC_TIMESTAMP() WHERE `repair_key` = %s',
                    $attempt_count,
                    $repair_key
                )
            )
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
