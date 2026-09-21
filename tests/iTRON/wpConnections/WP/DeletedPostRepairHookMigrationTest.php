<?php

namespace iTRON\wpConnections\Tests\iTRON\wpConnections\WP;

use iTRON\wpConnections\Abstracts\Connection as AbstractConnection;
use iTRON\wpConnections\Abstracts\Storage;
use iTRON\wpConnections\AtomicStorageInterface;
use iTRON\wpConnections\Client;
use iTRON\wpConnections\ConnectionCollection;
use iTRON\wpConnections\Internal\DeletedPostRepairStatus;
use iTRON\wpConnections\Internal\RestRouteRegistry;
use iTRON\wpConnections\MetaCollection;
use iTRON\wpConnections\Query\Connection as ConnectionQuery;
use iTRON\wpConnections\Query\MetaCollection as MetaQueryCollection;
use iTRON\wpConnections\TransactionContext;
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
        add_filter('query', [ $this, 'preserve_real_repair_ledger_table' ], 11);
        $this->dropLedgerArtifacts();
        DeletedPostRepairHookMigrationStorage::$deletedPostIdsByClient = [];
        DeletedPostRepairHookMigrationStorage::$deletedPostIdsByStorage = [];
        DeletedPostRepairHookMigrationStorage::$failingClients = [];
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
                    $this->dropLedgerArtifacts();
                } finally {
                    if ($switched) {
                        restore_current_blog();
                    }
                }
            }
            remove_filter('wpConnections/factory/getStorage/class', [ $this, 'storageClass' ]);
            $this->dropLedgerArtifacts();
            $wpdb->tables = $this->wpdbTablesBefore;
        } finally {
            remove_filter('query', [ $this, 'preserve_real_repair_ledger_table' ], 11);
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

        self::assertSame(
            [],
            DeletedPostRepairHookMigrationStorage::$deletedPostIdsByStorage[ $siteAStorageId ] ?? []
        );
        self::assertSame(
            [ 541 ],
            DeletedPostRepairHookMigrationStorage::$deletedPostIdsByStorage[ $siteBStorageId ] ?? []
        );
    }

    public function preserve_real_repair_ledger_table(string $query): string
    {
        $table = preg_quote($this->table, '/');
        $query = (string) preg_replace(
            '/^CREATE\s+TEMPORARY\s+TABLE\s+`' . $table . '`/i',
            'CREATE TABLE `' . $this->table . '`',
            $query
        );

        return (string) preg_replace(
            '/^DROP\s+TEMPORARY\s+TABLE(\s+IF\s+EXISTS)?\s+`' . $table . '`/i',
            'DROP TABLE$1 `' . $this->table . '`',
            $query
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
