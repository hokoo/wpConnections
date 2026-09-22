<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Client;
use iTRON\wpConnections\Connection;
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
