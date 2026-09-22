<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use DateTimeImmutable;
use DateTimeZone;
use iTRON\wpConnections\Abstracts\Connection as AbstractConnection;
use iTRON\wpConnections\Abstracts\Storage;
use iTRON\wpConnections\AtomicStorageInterface;
use iTRON\wpConnections\Client;
use iTRON\wpConnections\ConnectionCollection;
use iTRON\wpConnections\Exceptions\StorageFailure;
use iTRON\wpConnections\Internal\DeletedPostRepairDiagnostic;
use iTRON\wpConnections\Internal\DeletedPostRepairIdentity;
use iTRON\wpConnections\Internal\DeletedPostRepairLedger;
use iTRON\wpConnections\Internal\DeletedPostRepairRuntime;
use iTRON\wpConnections\Internal\DeletedPostRepairStatus;
use iTRON\wpConnections\Internal\RestRouteRegistry;
use iTRON\wpConnections\Internal\WordPressDeletedPostRepairScheduler;
use iTRON\wpConnections\MetaCollection;
use iTRON\wpConnections\Query\Connection as ConnectionQuery;
use iTRON\wpConnections\Query\MetaCollection as MetaQueryCollection;
use iTRON\wpConnections\TransactionContext;
use LogicException;
use RuntimeException;

final class DeletedPostRepairHookMigrationStorage extends Storage implements AtomicStorageInterface
{
    public static array $deletedPostIdsByClient = [];
    public static array $deletedPostIdsByStorage = [];
    public static array $failingClients = [];

    private string $clientName;

    public function __construct(Client $client)
    {
        $this->clientName = $client->getName();
    }

    public function createConnection(ConnectionQuery $connectionQuery): int
    {
        return 1;
    }

    public function updateConnection(AbstractConnection $connection): bool
    {
        return true;
    }

    public function deleteSpecificConnections($connectionIDs): int
    {
        return 0;
    }

    public function deleteByObjectID(
        $objectIDs,
        string $relation = '',
        bool $onlyFrom = false,
        bool $onlyTo = false
    ): int {
        self::$deletedPostIdsByClient[ $this->clientName ][] = $objectIDs;
        self::$deletedPostIdsByStorage[ spl_object_id($this) ][] = $objectIDs;
        if (isset(self::$failingClients[ $this->clientName ])) {
            throw new RuntimeException('private custom storage failure');
        }

        return 0;
    }

    public function deleteDirectedConnections(
        ?int $from = null,
        ?int $to = null,
        string $relation = ''
    ): int {
        return 0;
    }

    public function findConnections(ConnectionQuery $params): ConnectionCollection
    {
        return new ConnectionCollection();
    }

    public function addConnectionMeta(int $objectID, MetaCollection $metaCollection): void
    {
    }

    public function removeConnectionMeta(int $objectID, MetaQueryCollection $metaQuery)
    {
        return 0;
    }

    public function runAtomically(callable $operation, TransactionContext $context)
    {
        return $operation();
    }
}

final class DeletedPostRepairHookNonAtomicStorage extends Storage
{
    public static int $cleanupCalls = 0;

    public function __construct(Client $client)
    {
        unset($client);
    }

    public function createConnection(ConnectionQuery $connectionQuery): int
    {
        return 1;
    }

    public function updateConnection(AbstractConnection $connection): bool
    {
        return true;
    }

    public function deleteSpecificConnections($connectionIDs): int
    {
        return 0;
    }

    public function deleteByObjectID(
        $objectIDs,
        string $relation = '',
        bool $onlyFrom = false,
        bool $onlyTo = false
    ): int {
        self::$cleanupCalls++;

        return 0;
    }

    public function deleteDirectedConnections(
        ?int $from = null,
        ?int $to = null,
        string $relation = ''
    ): int {
        return 0;
    }

    public function findConnections(ConnectionQuery $params): ConnectionCollection
    {
        return new ConnectionCollection();
    }

    public function addConnectionMeta(int $objectID, MetaCollection $metaCollection): void
    {
    }

    public function removeConnectionMeta(int $objectID, MetaQueryCollection $metaQuery)
    {
        return 0;
    }
}

class DeletedPostRepairHookMigrationTest extends \WP_UnitTestCase
{
    private const TABLE_BASENAME = 'wpconnections_repair';
    private const OWNERSHIP_OPTION = 'wpconnections_repair_schema_owner';

    private static int $clientSequence = 0;

    private array $clients = [];
    private array $wpdbTablesBefore = [];
    private string $table;

    public function set_up()
    {
        parent::set_up();

        global $wpdb;
        $this->wpdbTablesBefore = $wpdb->tables;
        $this->table = $wpdb->prefix . self::TABLE_BASENAME;
        $this->dropLedgerArtifacts();
        DeletedPostRepairHookMigrationStorage::$deletedPostIdsByClient = [];
        DeletedPostRepairHookMigrationStorage::$deletedPostIdsByStorage = [];
        DeletedPostRepairHookMigrationStorage::$failingClients = [];
        DeletedPostRepairHookNonAtomicStorage::$cleanupCalls = 0;
        add_filter('wpConnections/factory/getStorage/class', [ $this, 'storageClass' ]);
    }

    public function tear_down()
    {
        global $wpdb;

        try {
            foreach ($this->clients as [ $client, $siteId ]) {
                $switched = get_current_blog_id() !== $siteId;
                if ($switched) {
                    switch_to_blog($siteId);
                }
                try {
                    \iTRON\wpConnections\Internal\DeletedPostRepairRuntime::instance()->deactivateClient(
                        $client
                    );
                    RestRouteRegistry::instance()->deactivateClient($client);
                    remove_action('deleted_post', [ $client->getStorage(), 'deleteByObjectID' ], 10);
                    wp_clear_scheduled_hook(WordPressDeletedPostRepairScheduler::EVENT_HOOK);
                    $this->dropLedgerArtifacts();
                } finally {
                    if ($switched) {
                        restore_current_blog();
                    }
                }
            }
            \iTRON\wpConnections\Internal\DeletedPostRepairRuntime::instance()->resetForTests();
            remove_filter('wpConnections/factory/getStorage/class', [ $this, 'storageClass' ]);
            $this->dropLedgerArtifacts();
            $wpdb->tables = $this->wpdbTablesBefore;
        } finally {
            parent::tear_down();
        }
    }

    public function storageClass(): string
    {
        return DeletedPostRepairHookMigrationStorage::class;
    }

    public function test_direct_storage_callback_removal_no_longer_disables_cleanup(): void
    {
        $client = $this->newClient('direct-remove');
        $callback = [ $client->getStorage(), 'deleteByObjectID' ];

        self::assertFalse(has_action('deleted_post', $callback));
        self::assertFalse(remove_action('deleted_post', $callback, 10));

        do_action('deleted_post', 501, null);

        self::assertSame(
            [ 501 ],
            DeletedPostRepairHookMigrationStorage::$deletedPostIdsByClient[ $client->getName() ]
        );
    }

    public function test_semantic_disable_and_enable_control_manager_delivery_idempotently(): void
    {
        $client = $this->newClient('semantic-lifecycle');
        $callback = [ $client->getStorage(), 'deleteByObjectID' ];

        $client->disablePostDeletionCleanup();
        $client->disablePostDeletionCleanup();
        do_action('deleted_post', 511, null);
        self::assertSame(
            [],
            DeletedPostRepairHookMigrationStorage::$deletedPostIdsByClient[ $client->getName() ] ?? []
        );

        $client->enablePostDeletionCleanup();
        $client->enablePostDeletionCleanup();
        self::assertFalse(has_action('deleted_post', $callback));
        do_action('deleted_post', 512, null);

        self::assertSame(
            [ 512 ],
            DeletedPostRepairHookMigrationStorage::$deletedPostIdsByClient[ $client->getName() ]
        );
    }

    public function test_inited_hook_can_disable_before_manager_activation(): void
    {
        $name = 'repair-hook-early-disable-' . ++self::$clientSequence;
        $disable = static function (Client $client) use ($name): void {
            if ($name === $client->getName()) {
                $client->disablePostDeletionCleanup();
            }
        };
        add_action('wpConnections/client/inited', $disable);
        try {
            $client = $this->newNamedClient($name);
        } finally {
            remove_action('wpConnections/client/inited', $disable);
        }

        do_action('deleted_post', 513, null);
        self::assertSame(
            [],
            DeletedPostRepairHookMigrationStorage::$deletedPostIdsByClient[ $name ] ?? []
        );

        $client->enablePostDeletionCleanup();
        do_action('deleted_post', 514, null);
        self::assertSame(
            [ 514 ],
            DeletedPostRepairHookMigrationStorage::$deletedPostIdsByClient[ $name ]
        );
    }

    public function test_dispose_is_terminal_for_cleanup_but_not_direct_domain_references(): void
    {
        $client = $this->newClient('disposed-cleanup');
        $name = $client->getName();

        $client->dispose();
        $client->dispose();
        $client->disablePostDeletionCleanup();
        do_action('deleted_post', 515, null);
        self::assertSame(
            [],
            DeletedPostRepairHookMigrationStorage::$deletedPostIdsByClient[ $name ] ?? []
        );

        try {
            $client->enablePostDeletionCleanup();
            self::fail('A disposed Client must not reactivate deleted-post cleanup.');
        } catch (\iTRON\wpConnections\Exceptions\ClientRegisterFail $failure) {
            self::assertSame(4, $failure->getCode());
            self::assertSame(
                'Client integrations have been disposed and cannot be reactivated.',
                $failure->getMessage()
            );
        }

        self::assertSame(0, $client->getStorage()->deleteByObjectID(516));
        self::assertSame(
            [ 516 ],
            DeletedPostRepairHookMigrationStorage::$deletedPostIdsByClient[ $name ]
        );
    }

    public function test_disposal_during_repair_readiness_rolls_back_every_owner(): void
    {
        $name = 'repair-hook-dispose-during-readiness-' . ++self::$clientSequence;
        $constructingClient = null;
        $disposed = false;
        $capture = static function (Client $client) use ($name, &$constructingClient): void {
            if ($name === $client->getName()) {
                $constructingClient = $client;
            }
        };
        $disposeDuringReadiness = function (string $query) use (&$constructingClient, &$disposed): string {
            if (
                ! $disposed &&
                $constructingClient instanceof Client &&
                false !== stripos($query, 'CREATE TABLE') &&
                false !== strpos($query, self::TABLE_BASENAME)
            ) {
                $disposed = true;
                $constructingClient->dispose();
            }

            return $query;
        };

        add_action('wpConnections/client/inited', $capture, 10, 1);
        add_filter('query', $disposeDuringReadiness, 8, 1);
        try {
            try {
                new Client($name);
                self::fail('Disposal during repair readiness must abort Client initialization.');
            } catch (\iTRON\wpConnections\Exceptions\ClientRegisterFail $failure) {
                self::assertSame(4, $failure->getCode());
                self::assertSame(
                    'Client integrations have been disposed and cannot be reactivated.',
                    $failure->getMessage()
                );
            }
        } finally {
            remove_filter('query', $disposeDuringReadiness, 8);
            remove_action('wpConnections/client/inited', $capture, 10);
            $constructingClient = null;
        }

        self::assertTrue($disposed);
        $replacement = $this->newNamedClient($name);
        do_action('deleted_post', 517, null);
        self::assertSame(
            [ 517 ],
            DeletedPostRepairHookMigrationStorage::$deletedPostIdsByClient[ $replacement->getName() ]
        );
    }

    public function test_disposal_during_reenable_cannot_restore_cleanup_subscription(): void
    {
        $client = $this->newClient('dispose-during-reenable');
        $name = $client->getName();
        $client->disablePostDeletionCleanup();
        $disposed = false;
        $disposeDuringReadiness = function (string $query) use ($client, &$disposed): string {
            if (
                ! $disposed &&
                false !== stripos($query, 'information_schema') &&
                false !== strpos($query, self::TABLE_BASENAME)
            ) {
                $disposed = true;
                $client->dispose();
            }

            return $query;
        };

        add_filter('query', $disposeDuringReadiness, 8, 1);
        try {
            try {
                $client->enablePostDeletionCleanup();
                self::fail('Reentrant disposal must prevent cleanup reactivation.');
            } catch (\iTRON\wpConnections\Exceptions\ClientRegisterFail $failure) {
                self::assertSame(4, $failure->getCode());
                self::assertSame(
                    'Client integrations have been disposed and cannot be reactivated.',
                    $failure->getMessage()
                );
            }
        } finally {
            remove_filter('query', $disposeDuringReadiness, 8);
        }

        self::assertTrue($disposed);
        do_action('deleted_post', 518, null);
        self::assertSame(
            [],
            DeletedPostRepairHookMigrationStorage::$deletedPostIdsByClient[ $name ] ?? []
        );

        $replacement = $this->newNamedClient($name);
        do_action('deleted_post', 519, null);
        self::assertSame(
            [ 519 ],
            DeletedPostRepairHookMigrationStorage::$deletedPostIdsByStorage[
                spl_object_id($replacement->getStorage())
            ]
        );
    }

    public function test_disposed_client_is_not_retained_by_library_registries(): void
    {
        $client = new Client('repair-hook-weak-reference-' . ++self::$clientSequence);
        $reference = \WeakReference::create($client);

        $client->dispose();
        unset($client);
        gc_collect_cycles();

        self::assertNull($reference->get());
    }

    public function test_schema_failure_does_not_leave_a_callable_site_cron_handler(): void
    {
        $ledger = new DeletedPostRepairLedger();
        $ledger->ensureReady();
        delete_option(self::OWNERSHIP_OPTION);
        wp_cache_delete(self::OWNERSHIP_OPTION, 'options');

        try {
            $this->newClient('unowned-schema');
            self::fail('An unowned repair ledger must reject Client initialization.');
        } catch (StorageFailure $failure) {
            self::assertStringContainsString('ownership', $failure->getMessage());
        }

        self::assertFalse(has_action(WordPressDeletedPostRepairScheduler::EVENT_HOOK));
    }

    public function test_disabled_initialization_reconciles_a_lost_retention_wakeup(): void
    {
        global $wpdb;

        $seed = $this->newClient('retention-seed');
        $ledger = new DeletedPostRepairLedger();
        $ledger->assertReady();
        $initialAt = new DateTimeImmutable('-30 days', new DateTimeZone('UTC'));
        $retryAt = new DateTimeImmutable('-29 days', new DateTimeZone('UTC'));
        $resolvedAt = $retryAt->modify('+1 minute');
        $identity = new DeletedPostRepairIdentity(
            (int) get_current_blog_id(),
            (string) $wpdb->prefix,
            $seed->getName(),
            'delete_post_connections:v1',
            515
        );
        $initial = $ledger->armAndTryClaim(
            $identity,
            $seed->getStorage(),
            $initialAt,
            $initialAt->modify('+10 minutes')
        )->getLease();
        self::assertNotNull($initial);
        self::assertTrue($ledger->markNeedsAttention(
            $initial,
            new DeletedPostRepairDiagnostic('storage', RuntimeException::class, '73', 'private'),
            $initialAt->modify('+1 minute')
        ));
        $retry = $ledger->tryClaimManually(
            $identity->getKey(),
            $seed->getStorage(),
            $retryAt,
            $retryAt->modify('+10 minutes')
        )->getLease();
        self::assertNotNull($retry);
        self::assertTrue($ledger->markResolved($retry, $resolvedAt));

        DeletedPostRepairRuntime::instance()->resetForTests();
        wp_clear_scheduled_hook(WordPressDeletedPostRepairScheduler::EVENT_HOOK);

        $name = 'repair-hook-retention-disabled-' . ++self::$clientSequence;
        $disable = static function (Client $client) use ($name): void {
            if ($name === $client->getName()) {
                $client->disablePostDeletionCleanup();
            }
        };
        add_action('wpConnections/client/inited', $disable);
        try {
            $this->newNamedClient($name);
        } finally {
            remove_action('wpConnections/client/inited', $disable);
        }

        self::assertIsInt(
            wp_next_scheduled(WordPressDeletedPostRepairScheduler::EVENT_HOOK, [])
        );
    }

    public function test_repeated_enable_from_an_inactive_site_is_rejected(): void
    {
        if (! is_multisite()) {
            self::markTestSkipped('Requires the true WordPress multisite lane.');
        }

        $client = $this->newClient('stale-repeated-enable');
        $siteB = self::factory()->blog->create();
        switch_to_blog($siteB);
        try {
            try {
                $client->enablePostDeletionCleanup();
                self::fail('A stale Client command must fail before the idempotent fast path.');
            } catch (LogicException $failure) {
                self::assertStringContainsString('context changed', $failure->getMessage());
            }
        } finally {
            restore_current_blog();
        }

        do_action('deleted_post', 519, null);
        self::assertSame(
            [ 519 ],
            DeletedPostRepairHookMigrationStorage::$deletedPostIdsByClient[ $client->getName() ]
        );
    }

    public function test_failed_initialization_leaves_no_repair_owner_for_replacement(): void
    {
        $name = 'repair-hook-init-failure-' . ++self::$clientSequence;
        $failure = static function (Client $client) use ($name): void {
            if ($name === $client->getName()) {
                throw new RuntimeException('intentional initialization failure');
            }
        };
        add_action("wpConnections/client/{$name}/inited", $failure);
        try {
            new Client($name);
            self::fail('The initialization fixture must fail before repair activation.');
        } catch (RuntimeException $caught) {
            self::assertSame('intentional initialization failure', $caught->getMessage());
        } finally {
            remove_action("wpConnections/client/{$name}/inited", $failure);
        }

        $replacement = $this->newNamedClient($name);
        do_action('deleted_post', 515, null);
        self::assertSame(
            [ 515 ],
            DeletedPostRepairHookMigrationStorage::$deletedPostIdsByClient[ $replacement->getName() ]
        );
    }

    public function test_manager_subscription_uses_priority_ten_and_one_accepted_argument(): void
    {
        global $wp_filter;

        $before = array_keys($wp_filter['deleted_post']->callbacks[10] ?? []);
        $this->newClient('hook-shape');
        $after = $wp_filter['deleted_post']->callbacks[10] ?? [];
        $added = array_values(array_diff(array_keys($after), $before));

        self::assertCount(1, $added);
        self::assertSame(1, $after[ $added[0] ]['accepted_args']);
    }

    public function test_site_has_one_zero_argument_cron_subscription(): void
    {
        global $wp_filter;

        $hook = WordPressDeletedPostRepairScheduler::EVENT_HOOK;
        $before = array_keys($wp_filter[ $hook ]->callbacks[10] ?? []);
        $this->newClient('cron-shape-first');
        $this->newClient('cron-shape-second');
        $after = $wp_filter[ $hook ]->callbacks[10] ?? [];
        $added = array_values(array_diff(array_keys($after), $before));

        self::assertCount(1, $added);
        self::assertSame(0, $after[ $added[0] ]['accepted_args']);
    }

    public function test_cron_retries_due_failure_and_duplicate_delivery_is_harmless(): void
    {
        $client = $this->newClient('cron-retry');
        DeletedPostRepairHookMigrationStorage::$failingClients[ $client->getName() ] = true;
        do_action('deleted_post', 516, null);
        unset(DeletedPostRepairHookMigrationStorage::$failingClients[ $client->getName() ]);
        $this->makeClientRepairDue($client);

        $this->dispatchScheduledCron();

        self::assertSame(
            [ 516, 516 ],
            DeletedPostRepairHookMigrationStorage::$deletedPostIdsByClient[ $client->getName() ]
        );
        self::assertCount(
            1,
            $client->getDeletedPostRepairService()->listRepairs(
                DeletedPostRepairStatus::RESOLVED
            )->getItems()
        );

        $this->dispatchScheduledCron();
        self::assertSame(
            [ 516, 516 ],
            DeletedPostRepairHookMigrationStorage::$deletedPostIdsByClient[ $client->getName() ]
        );
    }

    public function test_cron_skips_due_work_while_client_is_disabled_and_reenable_recovers(): void
    {
        $client = $this->newClient('cron-disabled');
        DeletedPostRepairHookMigrationStorage::$failingClients[ $client->getName() ] = true;
        do_action('deleted_post', 517, null);
        unset(DeletedPostRepairHookMigrationStorage::$failingClients[ $client->getName() ]);
        $this->makeClientRepairDue($client);
        $client->disablePostDeletionCleanup();

        $this->dispatchScheduledCron();
        self::assertSame(
            [ 517 ],
            DeletedPostRepairHookMigrationStorage::$deletedPostIdsByClient[ $client->getName() ]
        );

        $client->enablePostDeletionCleanup();
        $this->dispatchScheduledCron();
        self::assertSame(
            [ 517, 517 ],
            DeletedPostRepairHookMigrationStorage::$deletedPostIdsByClient[ $client->getName() ]
        );
    }

    public function test_non_atomic_storage_performs_zero_writes_and_exposes_redacted_attention(): void
    {
        remove_filter('wpConnections/factory/getStorage/class', [ $this, 'storageClass' ]);
        $nonAtomic = static function (): string {
            return DeletedPostRepairHookNonAtomicStorage::class;
        };
        add_filter('wpConnections/factory/getStorage/class', $nonAtomic);
        try {
            $client = $this->newClient('non-atomic');
        } finally {
            remove_filter('wpConnections/factory/getStorage/class', $nonAtomic);
            add_filter('wpConnections/factory/getStorage/class', [ $this, 'storageClass' ]);
        }

        do_action('deleted_post', 518, null);

        self::assertSame(0, DeletedPostRepairHookNonAtomicStorage::$cleanupCalls);
        $repairs = $client->getDeletedPostRepairService()->listRepairs(
            DeletedPostRepairStatus::NEEDS_ATTENTION
        )->getItems();
        self::assertCount(1, $repairs);
        self::assertSame('adapter', $repairs[0]->getFailureCategory());
        self::assertSame('[diagnostic details redacted]', $repairs[0]->getFailureSummary());
    }

    public function test_persisted_failure_of_one_client_does_not_stop_later_client_cleanup(): void
    {
        $failing = $this->newClient('failing-first');
        $later = $this->newClient('later-success');
        DeletedPostRepairHookMigrationStorage::$failingClients[ $failing->getName() ] = true;

        do_action('deleted_post', 521, null);

        self::assertSame(
            [ 521 ],
            DeletedPostRepairHookMigrationStorage::$deletedPostIdsByClient[ $failing->getName() ]
        );
        self::assertSame(
            [ 521 ],
            DeletedPostRepairHookMigrationStorage::$deletedPostIdsByClient[ $later->getName() ]
        );
        $repairs = $failing->getDeletedPostRepairService()->listRepairs(
            DeletedPostRepairStatus::RETRY_WAIT
        );
        self::assertCount(1, $repairs->getItems());
        self::assertSame(521, $repairs->getItems()[0]->getPostId());
    }

    public function test_disposing_one_client_preserves_neighbor_and_allows_replacement(): void
    {
        $disposed = $this->newClient('disposed-neighbor');
        $neighbor = $this->newClient('live-neighbor');
        $disposedName = $disposed->getName();
        $neighborName = $neighbor->getName();

        $disposed->dispose();
        do_action('deleted_post', 522, null);

        self::assertSame(
            [],
            DeletedPostRepairHookMigrationStorage::$deletedPostIdsByClient[ $disposedName ] ?? []
        );
        self::assertSame(
            [ 522 ],
            DeletedPostRepairHookMigrationStorage::$deletedPostIdsByClient[ $neighborName ]
        );

        $replacement = $this->newNamedClient($disposedName);
        do_action('deleted_post', 523, null);
        self::assertSame(
            [ 523 ],
            DeletedPostRepairHookMigrationStorage::$deletedPostIdsByStorage[
                spl_object_id($replacement->getStorage())
            ]
        );
        self::assertSame(
            [ 522, 523 ],
            DeletedPostRepairHookMigrationStorage::$deletedPostIdsByClient[ $neighborName ]
        );
    }

    public function test_multisite_disposal_does_not_revoke_same_name_client_on_another_site(): void
    {
        if (! is_multisite()) {
            self::markTestSkipped('Requires the true WordPress multisite lane.');
        }

        $name = 'repair-hook-disposed-multisite-' . ++self::$clientSequence;
        $siteAClient = $this->newNamedClient($name);
        $siteAStorageId = spl_object_id($siteAClient->getStorage());
        $siteB = self::factory()->blog->create();

        switch_to_blog($siteB);
        try {
            $siteBClient = $this->newNamedClient($name);
            $siteBStorageId = spl_object_id($siteBClient->getStorage());
            $siteBClient->dispose();
            do_action('deleted_post', 524, null);
            self::assertSame(
                [],
                DeletedPostRepairHookMigrationStorage::$deletedPostIdsByStorage[ $siteBStorageId ] ?? []
            );

            $replacement = $this->newNamedClient($name);
            do_action('deleted_post', 525, null);
            self::assertSame(
                [ 525 ],
                DeletedPostRepairHookMigrationStorage::$deletedPostIdsByStorage[
                    spl_object_id($replacement->getStorage())
                ]
            );
        } finally {
            restore_current_blog();
        }

        do_action('deleted_post', 526, null);
        self::assertSame(
            [ 526 ],
            DeletedPostRepairHookMigrationStorage::$deletedPostIdsByStorage[ $siteAStorageId ]
        );
    }

    public function test_inactive_site_manager_subscription_never_calls_custom_storage(): void
    {
        if (! is_multisite()) {
            self::markTestSkipped('Requires the true WordPress multisite lane.');
        }

        $siteAClient = $this->newClient('multisite-context');
        $siteB = self::factory()->blog->create();

        switch_to_blog($siteB);
        try {
            do_action('deleted_post', 531, null);
        } finally {
            restore_current_blog();
        }

        self::assertSame(
            [],
            DeletedPostRepairHookMigrationStorage::$deletedPostIdsByClient[ $siteAClient->getName() ] ?? []
        );
    }

    public function test_same_name_clients_on_two_sites_dispatch_only_the_active_owner(): void
    {
        if (! is_multisite()) {
            self::markTestSkipped('Requires the true WordPress multisite lane.');
        }

        $name = 'repair-hook-same-name-' . ++self::$clientSequence;
        $siteAClient = $this->newNamedClient($name);
        $siteAStorageId = spl_object_id($siteAClient->getStorage());
        $siteB = self::factory()->blog->create();

        switch_to_blog($siteB);
        try {
            $siteBClient = $this->newNamedClient($name);
            $siteBStorageId = spl_object_id($siteBClient->getStorage());
            do_action('deleted_post', 541, null);
        } finally {
            restore_current_blog();
        }

        do_action('deleted_post', 541, null);

        self::assertSame(
            [ 541 ],
            DeletedPostRepairHookMigrationStorage::$deletedPostIdsByStorage[ $siteAStorageId ]
        );
        self::assertSame(
            [ 541 ],
            DeletedPostRepairHookMigrationStorage::$deletedPostIdsByStorage[ $siteBStorageId ] ?? []
        );
    }

    public function test_same_name_multisite_cron_runs_only_the_active_site_worker(): void
    {
        if (! is_multisite()) {
            self::markTestSkipped('Requires the true WordPress multisite lane.');
        }

        $name = 'repair-hook-cron-same-name-' . ++self::$clientSequence;
        DeletedPostRepairHookMigrationStorage::$failingClients[ $name ] = true;
        $siteAClient = $this->newNamedClient($name);
        $siteAStorageId = spl_object_id($siteAClient->getStorage());
        do_action('deleted_post', 551, null);
        $this->makeClientRepairDue($siteAClient);

        $siteB = self::factory()->blog->create();
        switch_to_blog($siteB);
        try {
            $siteBClient = $this->newNamedClient($name);
            $siteBStorageId = spl_object_id($siteBClient->getStorage());
            do_action('deleted_post', 551, null);
            $this->makeClientRepairDue($siteBClient);
            unset(DeletedPostRepairHookMigrationStorage::$failingClients[ $name ]);
            $this->dispatchScheduledCron();

            self::assertSame(
                [ 551 ],
                DeletedPostRepairHookMigrationStorage::$deletedPostIdsByStorage[ $siteAStorageId ]
            );
            self::assertSame(
                [ 551, 551 ],
                DeletedPostRepairHookMigrationStorage::$deletedPostIdsByStorage[ $siteBStorageId ]
            );
        } finally {
            restore_current_blog();
        }

        $this->dispatchScheduledCron();
        self::assertSame(
            [ 551, 551 ],
            DeletedPostRepairHookMigrationStorage::$deletedPostIdsByStorage[ $siteAStorageId ]
        );
        self::assertSame(
            [ 551, 551 ],
            DeletedPostRepairHookMigrationStorage::$deletedPostIdsByStorage[ $siteBStorageId ]
        );
    }

    private function newClient(string $suffix): Client
    {
        return $this->newNamedClient(
            'repair-hook-' . $suffix . '-' . ++self::$clientSequence
        );
    }

    private function newNamedClient(string $name): Client
    {
        $client = new Client($name);
        $this->clients[] = [ $client, get_current_blog_id() ];

        return $client;
    }

    private function makeClientRepairDue(Client $client): void
    {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_BASENAME;
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}` SET `next_attempt_at` = UTC_TIMESTAMP(), " .
                '`updated_at` = UTC_TIMESTAMP() WHERE `client_name` = %s',
                $client->getName()
            )
        );
        self::assertSame(1, $updated);
    }

    private function dispatchScheduledCron(): void
    {
        $hook = WordPressDeletedPostRepairScheduler::EVENT_HOOK;
        $timestamp = wp_next_scheduled($hook, []);
        self::assertIsInt($timestamp);
        self::assertTrue(wp_unschedule_event($timestamp, $hook, [], true));
        do_action($hook);
    }

    private function dropLedgerArtifacts(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_BASENAME;
        delete_option(self::OWNERSHIP_OPTION);
        wp_cache_delete(self::OWNERSHIP_OPTION, 'options');
        $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        unset($wpdb->{self::TABLE_BASENAME});
        $wpdb->tables = array_values(
            array_filter(
                $wpdb->tables,
                static fn(string $tableKey): bool => self::TABLE_BASENAME !== $tableKey
            )
        );
    }
}
