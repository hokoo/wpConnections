<?php

namespace iTRON\wpConnections;

use iTRON\wpConnections\Exceptions\ConnectionWrongData;
use iTRON\wpConnections\Exceptions\ClientRegisterFail;
use iTRON\wpConnections\Exceptions\StorageFailure;
use iTRON\wpConnections\Helpers\Database;
use iTRON\wpConnections\Internal\ConnectionIdNormalizer;

class WPStorage extends Abstracts\Storage implements AtomicStorageInterface
{
    use ClientInterface;

    public const CONNECTIONS_TABLE_PREFIX = 'post_connections_';
    public const META_TABLE_PREFIX = 'post_connections_meta_';

    private const OWNERSHIP_OPTION_PREFIX = 'wpconnections_storage_owner_';
    private const OWNERSHIP_VERSION = 1;
    private const REQUIRED_ENGINE = 'INNODB';
    private const CONNECTIONS_COLUMNS = [
        'ID'       => [
            'type' => 'bigint unsigned',
            'nullable' => false,
            'default' => null,
            'extra' => 'auto_increment',
        ],
        'relation' => [
            'type' => 'varchar(255)',
            'nullable' => false,
            'default' => null,
            'extra' => '',
        ],
        'from'     => [
            'type' => 'bigint unsigned',
            'nullable' => false,
            'default' => null,
            'extra' => '',
        ],
        'to'       => [
            'type' => 'bigint unsigned',
            'nullable' => false,
            'default' => null,
            'extra' => '',
        ],
        'order'    => [
            'type' => 'bigint unsigned',
            'nullable' => true,
            'default' => '0',
            'extra' => '',
        ],
        'title'    => [
            'type' => 'varchar(63)',
            'nullable' => true,
            'default' => '',
            'extra' => '',
        ],
    ];
    private const META_COLUMNS = [
        'meta_id'       => [
            'type' => 'bigint unsigned',
            'nullable' => false,
            'default' => null,
            'extra' => 'auto_increment',
        ],
        'connection_id' => [
            'type' => 'bigint unsigned',
            'nullable' => false,
            'default' => '0',
            'extra' => '',
        ],
        'meta_key'      => [
            'type' => 'varchar(255)',
            'nullable' => false,
            'default' => null,
            'extra' => '',
        ],
        'meta_value'    => [
            'type' => 'longtext',
            'nullable' => false,
            'default' => null,
            'extra' => '',
        ],
    ];
    private const CONNECTIONS_INDEXES = [
        'PRIMARY'  => [
            'non_unique' => 0,
            'type' => 'BTREE',
            'columns' => [ [ 'name' => 'ID', 'prefix' => null ] ],
        ],
        'from'     => [
            'non_unique' => 1,
            'type' => 'BTREE',
            'columns' => [ [ 'name' => 'from', 'prefix' => null ] ],
        ],
        'to'       => [
            'non_unique' => 1,
            'type' => 'BTREE',
            'columns' => [ [ 'name' => 'to', 'prefix' => null ] ],
        ],
        'order'    => [
            'non_unique' => 1,
            'type' => 'BTREE',
            'columns' => [ [ 'name' => 'order', 'prefix' => null ] ],
        ],
        'relation' => [
            'non_unique' => 1,
            'type' => 'BTREE',
            'columns' => [ [ 'name' => 'relation', 'prefix' => null ] ],
        ],
    ];
    private const META_INDEXES = [
        'PRIMARY'       => [
            'non_unique' => 0,
            'type' => 'BTREE',
            'columns' => [ [ 'name' => 'meta_id', 'prefix' => null ] ],
        ],
        'connection_id' => [
            'non_unique' => 1,
            'type' => 'BTREE',
            'columns' => [ [ 'name' => 'connection_id', 'prefix' => null ] ],
        ],
        'meta_key'      => [
            'non_unique' => 1,
            'type' => 'BTREE',
            'columns' => [ [ 'name' => 'meta_key', 'prefix' => null ] ],
        ],
    ];

    private string $connections_table;
    private string $meta_table;
    private string $postfix;
    private string $site_prefix;
    private int $transactionDepth = 0;
    private ?\Throwable $transactionTaint = null;

    private static int $savepointSequence = 0;

    /**
     * @param Client $client wpConnections Client
     */
    public function __construct(Client $client)
    {
        global $wpdb;

        $this->client = $client;
        $this->site_prefix = (string) $wpdb->prefix;
        $this->postfix = str_replace('-', '_', $client->getName());
        $this->connections_table = self::CONNECTIONS_TABLE_PREFIX . $this->postfix;
        $this->meta_table = self::META_TABLE_PREFIX . $this->postfix;

        $this->preflight();
        $this->init();
    }

    public function get_connections_table(): string
    {
        $this->assertSitePrefix();
        return $this->connections_table;
    }

    public function get_meta_table(): string
    {
        $this->assertSitePrefix();
        return $this->meta_table;
    }

    /**
     * @return mixed Callback result.
     */
    public function runAtomically(callable $operation, TransactionContext $context)
    {
        global $wpdb;

        $this->assertSitePrefix();
        if (null !== $this->transactionTaint) {
            throw $this->storageFailure(
                'transaction state is uncertain',
                '',
                $this->transactionTaint
            );
        }

        if ($context->isRoot() && 0 < $this->transactionDepth) {
            throw new Exceptions\StorageCapabilityUnavailable(
                'A root transaction cannot start inside an active library scope.'
            );
        }

        if ($context->isSchemaRecoveryAllowed()) {
            $this->ensureSchemaReadyForInsert();
        } else {
            $this->assertSchemaReady();
        }

        $savepoint = '';
        if ($context->isRoot()) {
            if (false === $wpdb->query('START TRANSACTION')) {
                throw $this->storageFailure('start transaction');
            }
        } else {
            $savepoint = $this->nextSavepoint();
            if (false === $wpdb->query("SAVEPOINT {$savepoint}")) {
                throw $this->storageFailure('create savepoint');
            }
        }

        $this->transactionDepth++;
        try {
            $result = $operation();
        } catch (\Throwable $exception) {
            $this->transactionDepth--;
            $failure = $this->transactionTaint ?? $exception;
            $this->rollBackAtomicScope($context, $savepoint, $failure);
            throw $failure;
        }

        $this->transactionDepth--;
        if (null !== $this->transactionTaint) {
            $failure = $this->transactionTaint;
            $this->rollBackAtomicScope($context, $savepoint, $failure);
            throw $failure;
        }

        $completion = $context->isRoot() ? 'COMMIT' : "RELEASE SAVEPOINT {$savepoint}";
        if (false === $wpdb->query($completion)) {
            $error = $this->databaseError();
            $completionFailure = $this->storageFailure(
                $context->isRoot() ? 'commit transaction' : 'release savepoint',
                $error
            );

            if ($context->isRoot()) {
                if (false === $wpdb->query('ROLLBACK')) {
                    $rollbackFailure = $this->storageFailure(
                        'rollback transaction after commit failure',
                        '',
                        $completionFailure
                    );
                    $this->transactionTaint = $rollbackFailure;
                    throw $rollbackFailure;
                }

                throw $completionFailure;
            }

            if (false === $wpdb->query("ROLLBACK TO SAVEPOINT {$savepoint}")) {
                $rollbackFailure = $this->storageFailure(
                    'rollback savepoint after release failure',
                    '',
                    $completionFailure
                );
                $this->transactionTaint = $rollbackFailure;
                throw $rollbackFailure;
            }

            if (false === $wpdb->query("RELEASE SAVEPOINT {$savepoint}")) {
                throw $this->storageFailure(
                    'release savepoint after rollback',
                    '',
                    $completionFailure
                );
            }

            throw $completionFailure;
        }

        return $result;
    }

    private function rollBackAtomicScope(
        TransactionContext $context,
        string $savepoint,
        \Throwable $failure
    ): void {
        global $wpdb;

        if ($context->isRoot()) {
            if (false === $wpdb->query('ROLLBACK')) {
                $rollbackFailure = $this->storageFailure('rollback transaction', '', $failure);
                $this->transactionTaint = $rollbackFailure;
                throw $rollbackFailure;
            }

            $this->transactionTaint = null;
            return;
        }

        if (false === $wpdb->query("ROLLBACK TO SAVEPOINT {$savepoint}")) {
            $rollbackFailure = $this->storageFailure('rollback savepoint', '', $failure);
            $this->transactionTaint = $rollbackFailure;
            throw $rollbackFailure;
        }

        $this->transactionTaint = null;
        if (false === $wpdb->query("RELEASE SAVEPOINT {$savepoint}")) {
            throw $this->storageFailure('release savepoint after rollback', '', $failure);
        }
    }

    private function nextSavepoint(): string
    {
        self::$savepointSequence++;

        return sprintf('wpconn_%x_%x', spl_object_id($this), self::$savepointSequence);
    }

    private function init()
    {
        $this->assertSitePrefix();
        $install_on_init = apply_filters('wpConnections/storage/installOnInit', false, $this->client);
        $this->assertSitePrefix();

        $this->registerTable($this->connections_table);
        $this->registerTable($this->meta_table);

        if (true !== $install_on_init) {
            return;
        }

        try {
            $this->install();
            $this->assertSchemaReady();
        } catch (ConnectionWrongData $exception) {
            throw new ClientRegisterFail($exception->getMessage(), 4, $exception);
        }
    }

    private function install(): void
    {
        $this->assertSitePrefix();
        $issues = $this->schemaIssues();
        $this->assertPresentTablesCompatible();

        $connectionsTable = $this->fullTableName($this->connections_table);
        $metaTable = $this->fullTableName($this->meta_table);
        $errors = [];

        if ('missing' === ($issues[$connectionsTable] ?? null)) {
            Database::install_table(
                $this->connections_table,
                "
                `ID`        bigint(20) unsigned NOT NULL auto_increment,
                `relation`  varchar(255) NOT NULL,
                `from`      bigint(20) unsigned NOT NULL,
                `to`        bigint(20) unsigned NOT NULL,
                `order`     bigint(20) unsigned NULL default '0',
                `title`     varchar(63)  NULL default '',
                PRIMARY KEY  (`ID`),
                KEY `from` (`from`),
                KEY `to` (`to`),
                KEY `order` (`order`),
                KEY `relation` (`relation`)
                ",
                [ 'table_options' => 'ENGINE=InnoDB' ]
            );
            $errors[$connectionsTable] = $this->databaseError();
        }

        if ('missing' === ($issues[$metaTable] ?? null)) {
            Database::install_table(
                $this->meta_table,
                "
                `meta_id`       bigint(20) unsigned NOT NULL auto_increment,
                `connection_id` bigint(20) unsigned NOT NULL default '0',
                `meta_key`      varchar(255) NOT NULL,
                `meta_value`    longtext     NOT NULL,
                PRIMARY KEY  (`meta_id`),
                KEY `connection_id` (`connection_id`),
                KEY `meta_key` (`meta_key`)
                ",
                [ 'table_options' => 'ENGINE=InnoDB' ]
            );
            $errors[$metaTable] = $this->databaseError();
        }

        $errors = array_filter($errors);
        if ([] !== $errors) {
            throw $this->schemaException('installation failed', $errors);
        }
    }

    /**
     * Validates and claims the concrete table mapping before registration.
     *
     * @throws ClientRegisterFail
     */
    private function preflight(): void
    {
        $this->assertSitePrefix();

        if (
            strlen($this->site_prefix . $this->connections_table) > 64 ||
            strlen($this->site_prefix . $this->meta_table) > 64
        ) {
            throw new ClientRegisterFail('Client table identifier exceeds the 64-character database limit.');
        }

        $connectionsExists = $this->tableExists($this->site_prefix . $this->connections_table);
        $metaExists = $this->tableExists($this->site_prefix . $this->meta_table);
        $record = $this->readOwnershipRecord();

        if (null !== $record) {
            $this->assertOwnershipRecord($record);

            if (
                ($connectionsExists && ! $this->hasExpectedSchema(
                    $this->site_prefix . $this->connections_table,
                    self::CONNECTIONS_COLUMNS
                )) || ($metaExists && ! $this->hasExpectedSchema(
                    $this->site_prefix . $this->meta_table,
                    self::META_COLUMNS
                ))
            ) {
                throw new ClientRegisterFail('Client table ownership is ambiguous; explicit migration is required.');
            }

            return;
        }

        if ($connectionsExists || $metaExists) {
            throw new ClientRegisterFail('Client table ownership is ambiguous; explicit migration is required.');
        }

        $record = [
            'version' => self::OWNERSHIP_VERSION,
            'postfix' => $this->postfix,
            'owner'   => $this->client->getName(),
        ];

        global $wpdb;
        $suppressErrors = $wpdb->suppress_errors();
        try {
            $claimAdded = add_option($this->ownershipOptionName(), $record, '', false);
        } finally {
            $wpdb->suppress_errors($suppressErrors);
        }

        if ($claimAdded) {
            return;
        }

        wp_cache_delete($this->ownershipOptionName(), 'options');
        wp_cache_delete('notoptions', 'options');
        $concurrentRecord = $this->readPersistedOwnershipRecord();
        if (null === $concurrentRecord) {
            throw new ClientRegisterFail('Client table ownership is ambiguous; explicit migration is required.');
        }

        $this->assertOwnershipRecord($concurrentRecord);
    }

    private function assertOwnershipRecord($record): void
    {
        if (
            ! is_array($record) ||
            self::OWNERSHIP_VERSION !== ($record['version'] ?? null) ||
            $this->postfix !== ($record['postfix'] ?? null) ||
            ! is_string($record['owner'] ?? null) ||
            ! preg_match('/^[a-z0-9_-]+$/D', $record['owner'])
        ) {
            throw new ClientRegisterFail('Client table ownership is ambiguous; explicit migration is required.');
        }

        if ($this->client->getName() !== $record['owner']) {
            throw new ClientRegisterFail('Client table mapping is already claimed by another client.');
        }
    }

    private function readOwnershipRecord()
    {
        $missing = new \stdClass();
        $record = get_option($this->ownershipOptionName(), $missing);

        return $missing === $record ? null : $record;
    }

    private function readPersistedOwnershipRecord()
    {
        global $wpdb;

        $serializedRecord = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                $this->ownershipOptionName()
            )
        );

        return null === $serializedRecord ? null : maybe_unserialize($serializedRecord);
    }

    private function ownershipOptionName(): string
    {
        return self::OWNERSHIP_OPTION_PREFIX . hash('sha256', $this->postfix);
    }

    private function tableExists(string $table): bool
    {
        global $wpdb;

        $escapedTable = str_replace('`', '``', $table);
        $suppressErrors = $wpdb->suppress_errors();
        try {
            $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$escapedTable}`");
        } finally {
            $wpdb->suppress_errors($suppressErrors);
        }

        return is_array($columns) && [] !== $columns;
    }

    private function hasExpectedSchema(string $table, array $expectedColumns): bool
    {
        return null === $this->columnSchemaIssue($table, $expectedColumns);
    }

    private function columnSchemaIssue(string $table, array $expectedColumns): ?string
    {
        global $wpdb;

        $escapedTable = str_replace('`', '``', $table);
        $columns = $wpdb->get_results("SHOW FULL COLUMNS FROM `{$escapedTable}`", ARRAY_A);
        if (count($expectedColumns) !== count($columns)) {
            return sprintf(
                'expected %d columns, found %d',
                count($expectedColumns),
                count($columns)
            );
        }

        $position = 0;
        foreach ($expectedColumns as $name => $expected) {
            $column = $columns[$position] ?? [];
            $actual = [
                'type' => $this->normalizeColumnType((string) ($column['Type'] ?? '')),
                'nullable' => 'YES' === ($column['Null'] ?? ''),
                'default' => $column['Default'] ?? null,
                'extra' => $this->normalizeColumnExtra((string) ($column['Extra'] ?? '')),
            ];

            if ($name !== ($column['Field'] ?? null)) {
                return sprintf(
                    'expected column %s at position %d, found %s',
                    $name,
                    $position + 1,
                    (string) ($column['Field'] ?? 'none')
                );
            }

            if ($expected !== $actual) {
                return sprintf(
                    'column %s expected %s, found %s',
                    $name,
                    wp_json_encode($expected),
                    wp_json_encode($actual)
                );
            }
            $position++;
        }

        return null;
    }

    private function normalizeColumnType(string $type): string
    {
        $type = strtolower(trim(preg_replace('/\s+/', ' ', $type)));

        return preg_replace(
            '/\b(tinyint|smallint|mediumint|int|integer|bigint)\([0-9]+\)/',
            '$1',
            $type
        );
    }

    private function normalizeColumnExtra(string $extra): string
    {
        $extra = strtolower(trim($extra));

        return 'null' === $extra ? '' : $extra;
    }

    /**
     * Ensures that both client tables are complete and transactional before
     * the first INSERT. Missing tables get one bounded dbDelta recovery cycle;
     * failed DML never triggers DDL.
     *
     * @throws ConnectionWrongData
     */
    private function ensureSchemaReadyForInsert(): void
    {
        $issues = $this->schemaIssues();
        if ([] === $issues) {
            return;
        }

        $this->assertPresentTablesCompatible();
        $this->install();
        $this->assertSchemaReady();
    }

    /**
     * Existing tables must never be changed implicitly by the recovery path.
     *
     * @throws ConnectionWrongData
     */
    private function assertPresentTablesCompatible(): void
    {
        $issues = array_filter(
            $this->schemaIssues(),
            static function (string $issue): bool {
                return 'missing' !== $issue;
            }
        );

        if ([] !== $issues) {
            throw $this->schemaException('existing table is incompatible', $issues);
        }
    }

    /**
     * @throws ConnectionWrongData
     */
    private function assertSchemaReady(): void
    {
        $issues = $this->schemaIssues();
        if ([] !== $issues) {
            throw $this->schemaException('recovery did not produce a ready schema', $issues);
        }
    }

    /**
     * @return array<string, string>
     */
    private function schemaIssues(): array
    {
        $tables = [
            $this->fullTableName($this->connections_table) => [
                'columns' => self::CONNECTIONS_COLUMNS,
                'indexes' => self::CONNECTIONS_INDEXES,
            ],
            $this->fullTableName($this->meta_table) => [
                'columns' => self::META_COLUMNS,
                'indexes' => self::META_INDEXES,
            ],
        ];
        $issues = [];

        foreach ($tables as $table => $expected) {
            if (! $this->tableExists($table)) {
                $issues[$table] = 'missing';
                continue;
            }

            $columnIssue = $this->columnSchemaIssue($table, $expected['columns']);
            if (null !== $columnIssue) {
                $issues[$table] = "incompatible columns ({$columnIssue})";
                continue;
            }

            if (! $this->hasRequiredIndexes($table, $expected['indexes'])) {
                $issues[$table] = 'missing or incompatible indexes';
                continue;
            }

            $engine = $this->tableEngine($table);
            if (self::REQUIRED_ENGINE !== $engine) {
                $issues[$table] = '' === $engine ? 'unknown engine' : "engine {$engine}";
            }
        }

        return $issues;
    }

    private function hasRequiredIndexes(string $table, array $expectedIndexes): bool
    {
        global $wpdb;

        $escapedTable = str_replace('`', '``', $table);
        $indexes = [];
        foreach ($wpdb->get_results("SHOW INDEX FROM `{$escapedTable}`", ARRAY_A) as $row) {
            $name = (string) $row['Key_name'];
            $nonUnique = (int) $row['Non_unique'];
            $type = strtoupper((string) $row['Index_type']);
            $usable = (! isset($row['Visible']) || 'YES' === strtoupper((string) $row['Visible'])) &&
                (! isset($row['Ignored']) || 'NO' === strtoupper((string) $row['Ignored']));

            if (! isset($indexes[$name])) {
                $indexes[$name] = [
                    'non_unique' => $nonUnique,
                    'type' => $type,
                    'usable' => $usable,
                    'columns' => [],
                ];
            } elseif (
                $indexes[$name]['non_unique'] !== $nonUnique ||
                $indexes[$name]['type'] !== $type ||
                $indexes[$name]['usable'] !== $usable
            ) {
                return false;
            }

            $subPart = $row['Sub_part'] ?? null;
            $indexes[$name]['columns'][(int) $row['Seq_in_index']] = [
                'name' => (string) $row['Column_name'],
                'prefix' => null === $subPart ? null : (int) $subPart,
            ];
        }

        foreach ($indexes as &$index) {
            ksort($index['columns'], SORT_NUMERIC);
            $index['columns'] = array_values($index['columns']);
        }
        unset($index);

        foreach ($expectedIndexes as $name => $expected) {
            if (! isset($indexes[$name]) || ! $indexes[$name]['usable']) {
                return false;
            }

            unset($indexes[$name]['usable']);
            if ($expected !== $indexes[$name]) {
                return false;
            }
        }

        return true;
    }

    private function tableEngine(string $table): string
    {
        global $wpdb;

        $escapedTable = str_replace('`', '``', $table);
        $definition = $wpdb->get_row("SHOW CREATE TABLE `{$escapedTable}`", ARRAY_N);
        if (! is_array($definition) || ! isset($definition[1])) {
            return '';
        }

        return preg_match('/\bENGINE=([A-Za-z0-9_]+)/i', $definition[1], $matches)
            ? strtoupper($matches[1])
            : '';
    }

    private function databaseError(): string
    {
        global $wpdb;

        return trim((string) $wpdb->last_error);
    }

    private function schemaException(string $stage, array $issues): ConnectionWrongData
    {
        $details = [];
        foreach ($issues as $table => $issue) {
            $details[] = "{$table}: {$issue}";
        }

        return new ConnectionWrongData(
            'Client storage schema is not ready for InnoDB DML; ' . $stage . ': [' . implode('; ', $details) . '].'
        );
    }

    private function registerTable(string $table): void
    {
        global $wpdb;

        $this->assertSitePrefix();
        if (! in_array($table, $wpdb->tables, true)) {
            $wpdb->tables[] = $table;
        }
        $wpdb->{$table} = $this->site_prefix . $table;
    }

    private function fullTableName(string $table): string
    {
        $this->assertSitePrefix();
        return $this->site_prefix . $table;
    }

    /**
     * @throws ClientRegisterFail
     */
    private function assertSitePrefix(): void
    {
        global $wpdb;

        if ($this->site_prefix !== (string) $wpdb->prefix) {
            throw new ClientRegisterFail('Client storage is bound to a different WordPress site prefix.');
        }
    }

    /**
     * Keeps the 1.x direct callback identity while ignoring inactive-site
     * cascade delivery. A context-aware subscription replaces this bridge in
     * the next major version.
     */
    private function isStaleDeletedPostContext(): bool
    {
        global $wpdb;

        return 'deleted_post' === current_filter() &&
            $this->site_prefix !== (string) $wpdb->prefix;
    }


    /**
     * Deletes connections by set of connection IDs
     *
     * @throws ConnectionWrongData
     *
     * @return int Rows number affected
     */
    public function deleteSpecificConnections($connectionIDs): int
    {
        $this->assertSitePrefix();

        do_action('wpConnections/storage/deleteSpecificConnections', $this->getClient(), $connectionIDs);
        do_action("wpConnections/client/{$this->getClient()->getName()}/storage/deleteSpecificConnections", $connectionIDs);
        $this->assertSitePrefix();

        $connectionIDs = $this->prepareIDs($connectionIDs);

        return (int) $this->getClient()->executeAtomicMutation(
            function () use ($connectionIDs): int {
                return $this->deleteSpecificConnectionsPrepared($connectionIDs);
            }
        );
    }

    /**
     * @param int[] $connectionIDs
     */
    private function deleteSpecificConnectionsPrepared(array $connectionIDs): int
    {
        global $wpdb;

        // MySQL Query
        $db = $this->fullTableName($this->connections_table);
        $db_meta = $this->fullTableName($this->meta_table);
        $in = $this->idPlaceholders($connectionIDs);

        $lockQuery = $wpdb->prepare(
            "SELECT `ID` FROM {$db} WHERE `ID` IN ({$in}) FOR UPDATE",
            ...$connectionIDs
        );
        $wpdb->get_results($lockQuery);
        $lockError = $this->databaseError();
        if ('' !== $lockError) {
            throw $this->storageFailure('lock connections for delete', $lockError);
        }

        $query = $wpdb->prepare(
            "DELETE FROM {$db} WHERE `ID` IN ({$in})",
            ...$connectionIDs
        );
        $query_meta = $wpdb->prepare(
            "DELETE FROM {$db_meta} WHERE `connection_id` IN ({$in})",
            ...$connectionIDs
        );

        if (false === $wpdb->query($query_meta)) {
            throw $this->storageFailure('delete connection metadata');
        }

        $rowsAffected = $wpdb->query($query);
        if (false === $rowsAffected) {
            throw $this->storageFailure('delete connections');
        }

        $rowsAffected = (int) $rowsAffected;

        if (0 === $rowsAffected) {
            return 0;
        }

        $client = $this->getClient();
        $client->deferSuccessNotification(
            static function () use ($client, $connectionIDs, $rowsAffected): void {
                do_action(
                    'wpConnections/storage/deletedSpecificConnections',
                    $client,
                    $connectionIDs,
                    $rowsAffected
                );
                do_action(
                    "wpConnections/client/{$client->getName()}/storage/deletedSpecificConnections",
                    $connectionIDs,
                    $rowsAffected
                );
            }
        );

        return $rowsAffected;
    }

    /**
     * Deletes connections by object ID(s).
     *
     * @param $objectIDs        int|int[]   Object ID(s) to delete connections with.
     * @param $relation         string      Relation name. Default all relations
     * @param $onlyFrom         bool        Affect connections with coinciding `from` id
     * @param $onlyTo           bool        Affect connections with coinciding `to` id
     *
     * @throws ConnectionWrongData
     *
     * @return                  int         Rows number affected
     */
    public function deleteByObjectID($objectIDs, string $relation = '', bool $onlyFrom = false, bool $onlyTo = false): int
    {
        if ($this->isStaleDeletedPostContext()) {
            return 0;
        }

        $this->assertSitePrefix();

        do_action('wpConnections/storage/deleteByObjectID', $this->getClient(), $objectIDs, $relation, $onlyFrom, $onlyTo);
        do_action("wpConnections/client/{$this->getClient()->getName()}/storage/deleteByObjectID", $objectIDs, $relation, $onlyFrom, $onlyTo);
        $this->assertSitePrefix();

        // Only one of direction restricts may be set true.
        if ($onlyFrom && $onlyTo) {
            throw new ConnectionWrongData('Only one object-side direction restriction may be enabled.');
        }

        $objectIDs = $this->prepareIDs($objectIDs);

        return (int) $this->getClient()->executeAtomicMutation(
            function () use ($objectIDs, $relation, $onlyFrom, $onlyTo): int {
                return $this->deleteByObjectIDPrepared(
                    $objectIDs,
                    $relation,
                    $onlyFrom,
                    $onlyTo
                );
            }
        );
    }

    /**
     * @param int[] $objectIDs
     */
    private function deleteByObjectIDPrepared(
        array $objectIDs,
        string $relation,
        bool $onlyFrom,
        bool $onlyTo
    ): int {
        global $wpdb;

        $in = $this->idPlaceholders($objectIDs);

        $where = [];
        $whereArguments = [];

        if (! $onlyFrom) {
            $where [] = "`to` IN ({$in})";
            array_push($whereArguments, ...$objectIDs);
        }

        if (! $onlyTo) {
            $where [] = "`from` IN ({$in})";
            array_push($whereArguments, ...$objectIDs);
        }

        $where_str = implode(' OR ', $where);

        $relationQuery = '' === $relation ? '1=1' : '`relation` = %s';
        $queryArguments = '' === $relation ? [] : [ $relation ];
        array_push($queryArguments, ...$whereArguments);
        $db = $this->fullTableName($this->connections_table);
        $db_meta = $this->fullTableName($this->meta_table);

        // Get ID's
        $query_ids = $wpdb->prepare(
            "SELECT `ID` FROM {$db} WHERE {$relationQuery} AND ({$where_str}) FOR UPDATE",
            ...$queryArguments
        );
        $result_ids = $wpdb->get_results($query_ids);
        $selectError = $this->databaseError();
        if ('' !== $selectError) {
            throw $this->storageFailure('select connections for delete', $selectError);
        }
        $ids = ( is_array($result_ids) && ! empty($result_ids) ) ? array_column($result_ids, 'ID') : [];

        // Nothing found.
        if (empty($ids)) {
            return 0;
        }

        // Delete
        $ids = $this->prepareIDs($ids);
        $in = $this->idPlaceholders($ids);
        $query_meta = $wpdb->prepare(
            "DELETE FROM {$db_meta} WHERE `connection_id` IN ({$in})",
            ...$ids
        );
        $query = $wpdb->prepare(
            "DELETE FROM {$db} WHERE `ID` IN ({$in})",
            ...$ids
        );

        if (false === $wpdb->query($query_meta)) {
            throw $this->storageFailure('delete connection metadata');
        }

        $rowsAffected = $wpdb->query($query);
        if (false === $rowsAffected) {
            throw $this->storageFailure('delete connections');
        }

        $rowsAffected = (int) $rowsAffected;
        if (0 === $rowsAffected) {
            return 0;
        }

        $client = $this->getClient();
        $client->deferSuccessNotification(
            static function () use ($client, $ids): void {
                do_action('wpConnections/storage/deletedByObjectID', $client, $ids);
                do_action(
                    "wpConnections/client/{$client->getName()}/storage/deletedByObjectID",
                    $ids
                );
            }
        );

        return $rowsAffected;
    }

    /**
     * Deletes exactly specified connections.
     * Able to erase multiple connections (e.g. if duplicatable is set true)
     *
     * @param mixed       $from      `from` object
     * @param mixed       $to        `to` object
     * @param string $relation  Relation name. Default all relations
     *
     * @return int              Rows number affected.
     */
    public function deleteDirectedConnections($from = null, $to = null, string $relation = ''): int
    {
        $this->assertSitePrefix();

        do_action('wpConnections/storage/deleteDirectedConnections', $this->getClient(), $from, $to, $relation);
        do_action("wpConnections/client/{$this->getClient()->getName()}/storage/deleteDirectedConnections", $from, $to, $relation);
        $this->assertSitePrefix();

        $from = ConnectionIdNormalizer::one($from);
        $to = ConnectionIdNormalizer::one($to);

        return (int) $this->getClient()->executeAtomicMutation(
            function () use ($from, $to, $relation): int {
                return $this->deleteDirectedConnectionsPrepared($from, $to, $relation);
            }
        );
    }

    private function deleteDirectedConnectionsPrepared(int $from, int $to, string $relation): int
    {
        global $wpdb;

        // MySQL Query
        $db = $this->fullTableName($this->connections_table);
        $db_meta = $this->fullTableName($this->meta_table);
        $relationQuery = '' === $relation ? '1=1' : '`relation` = %s';
        $queryArguments = '' === $relation ? [] : [ $relation ];
        $queryArguments[] = $from;
        $queryArguments[] = $to;

        // Get ID's
        $query_ids = $wpdb->prepare(
            "SELECT `ID` FROM {$db} WHERE {$relationQuery} AND `from` = %d AND `to` = %d FOR UPDATE",
            ...$queryArguments
        );
        $result_ids = $wpdb->get_results($query_ids);
        $selectError = $this->databaseError();
        if ('' !== $selectError) {
            throw $this->storageFailure('select connections for delete', $selectError);
        }
        $ids = ( is_array($result_ids) && ! empty($result_ids) ) ? array_column($result_ids, 'ID') : [];

        // Nothing found.
        if (empty($ids)) {
            return 0;
        }

        // Delete
        $ids = $this->prepareIDs($ids);
        $in = $this->idPlaceholders($ids);
        $query = $wpdb->prepare(
            "DELETE FROM {$db} WHERE `ID` IN ({$in})",
            ...$ids
        );
        $query_meta = $wpdb->prepare(
            "DELETE FROM {$db_meta} WHERE `connection_id` IN ({$in})",
            ...$ids
        );

        if (false === $wpdb->query($query_meta)) {
            throw $this->storageFailure('delete connection metadata');
        }

        $rowsAffected = $wpdb->query($query);
        if (false === $rowsAffected) {
            throw $this->storageFailure('delete connections');
        }

        $rowsAffected = (int) $rowsAffected;
        if (0 === $rowsAffected) {
            return 0;
        }

        $client = $this->getClient();
        $client->deferSuccessNotification(
            static function () use ($client, $ids): void {
                do_action('wpConnections/storage/deletedDirectedConnections', $client, $ids);
                do_action(
                    "wpConnections/client/{$client->getName()}/storage/deletedDirectedConnections",
                    $ids
                );
            }
        );

        return $rowsAffected;
    }

    /**
     * @throws ConnectionWrongData
     *
     * @param int|int[] $connectionIDs
     *
     * @return int[]
     */
    protected function prepareIDs($connectionIDs): array
    {
        return ConnectionIdNormalizer::many($connectionIDs);
    }

    /**
     * @param int[] $connectionIDs
     */
    private function idPlaceholders(array $connectionIDs): string
    {
        return implode(',', array_fill(0, count($connectionIDs), '%d'));
    }

    /**
     * Search connections
     *
     * @param Query\Connection $params
     *
     * @return ConnectionCollection
     */
    public function findConnections(Query\Connection $params): ConnectionCollection
    {
        global $wpdb;

        $this->assertSitePrefix();

        $where = [];

        if (is_numeric($id = $params->get('id')) && ! empty($id)) {
            $_where = $wpdb->prepare("c.ID = %d", $id);
            $_where .= $params->exists_relation() ? $wpdb->prepare(" AND c.relation = '%s'", $params->get('relation')) : '';
            $where [] = $_where;
        } else {
            if ($params->exists_relation()) {
                $where [] = $wpdb->prepare("c.relation = '%s'", $params->get('relation'));
            }

            if ($params->exists_from()) {
                $where [] = $wpdb->prepare("c.from = %d", $params->get('from'));
            }

            if ($params->exists_to()) {
                $where [] = $wpdb->prepare("c.to = %d", $params->get('to'));
            }

            if ($params->exists_both()) {
                $where [] = $wpdb->prepare(
                    "( c.from = %d OR c.to = %d )",
                    $params->get('both'),
                    $params->get('both')
                );
            }
        }

        if (empty($where)) {
            return new ConnectionCollection();
        }

        $where_str = implode(' AND ', $where);
        $db = $this->fullTableName($this->connections_table);
        $db_meta = $this->fullTableName($this->meta_table);
        $query = "SELECT c.*, m.* FROM {$db} c LEFT JOIN {$db_meta} m ON c.ID = m.connection_id WHERE {$where_str}";
        $query_result = $wpdb->get_results($query);

        do_action('wpConnections/storage/findConnections/dbQuery', $query, $query_result, $this->getClient());

        // Meta prepare
        $data = [];
        foreach ($query_result as $connection) {
            $item = $data[ $connection->ID ] ?? (array) $connection;
            $meta = $item[ 'meta' ] ?? [];

            if (is_numeric($connection->meta_id)) {
                if (empty($meta[ $connection->meta_key ])) {
                    $meta[ $connection->meta_key ] = [];
                }

                $meta[ $connection->meta_key ] [] = $connection->meta_value;
            }

            $item[ 'meta' ] = $meta;
            $data[ $connection->ID ] = $item;
        }

        $collection = new ConnectionCollection($data);

        do_action('wpConnections/storage/findConnections/dbQuery/data', $query, $query_result, $data, $collection->toArray());

        return $collection;
    }

    /**
     * @throws Exceptions\ConnectionWrongData
     *
     * @return int Connection ID
     */
    public function createConnection(Query\Connection $connectionQuery): int
    {
        $this->assertSitePrefix();
        $metaQuery = $connectionQuery->get('meta');
        /** @var Query\MetaCollection $metaQuery */
        if (! $metaQuery->isEmpty()) {
            return (int) $this->getClient()->executeAtomicMutation(
                function () use ($connectionQuery): int {
                    return $this->createConnectionPrepared($connectionQuery);
                },
                true
            );
        }

        if (! $this->getClient()->hasActiveAtomicScope()) {
            $this->ensureSchemaReadyForInsert();
        }

        return $this->createConnectionPrepared($connectionQuery);
    }

    private function createConnectionPrepared(Query\Connection $connectionQuery): int
    {
        global $wpdb;

        $metaQuery = $connectionQuery->get('meta');
        /** @var Query\MetaCollection $metaQuery */

        $data = [
            'from'      => $connectionQuery->get('from'),
            'to'        => $connectionQuery->get('to'),
            'order'     => $connectionQuery->get('order') ?? 0,
            'relation'  => $connectionQuery->get('relation'),
            'title'     => $connectionQuery->get('title'),
        ];

        do_action('iTRON/wpConnections/storage/createConnection/attempt', 0);
        $this->assertSitePrefix();
        $suppress = $wpdb->suppress_errors();
        $result = $wpdb->insert($this->fullTableName($this->connections_table), $data);
        $wpdb->suppress_errors($suppress);
        $insertError = $this->databaseError();
        do_action('iTRON/wpConnections/storage/createConnection/attempt/result', $result, $insertError);

        if (false === $result) {
            throw $this->storageFailure('create connection', $insertError);
        }

        $connection_id = $wpdb->insert_id;
        if (0 >= $connection_id) {
            throw $this->storageFailure('create connection');
        }

        // Insert meta data.
        if (! $metaQuery->isEmpty()) {
            $this->addConnectionMetaPrepared($connection_id, $metaQuery);
        }

        return $connection_id;
    }

    public function updateConnection(Abstracts\Connection $connection): bool
    {
        global $wpdb;

        $this->assertSitePrefix();

        $where = ['ID' => $connection->id];
        $update = [
            'from'      => $connection->from,
            'to'        => $connection->to,
            'order'     => $connection->order,
            'relation'  => $connection->relation,
            'title'     => $connection->title,
        ];

        $result = $wpdb->update($this->fullTableName($this->connections_table), $update, $where);
        if (false === $result) {
            throw $this->storageFailure('update connection');
        }

        return 0 !== $result;
    }

    /**
     * Only adds meta fields to the DB.
     *
     * @param int $objectID
     * @param MetaCollection $metaCollection
     *
     * @return void
     * @throws ConnectionWrongData
     */
    public function addConnectionMeta(int $objectID, MetaCollection $metaCollection): void
    {
        $this->assertSitePrefix();

        if ($metaCollection->isEmpty()) {
            throw new Exceptions\ConnectionWrongData("Meta object is empty.");
        }

        if (empty($objectID)) {
            throw new Exceptions\ConnectionWrongData("Object ID is empty.");
        }

        $this->getClient()->executeAtomicMutation(
            function () use ($objectID, $metaCollection): void {
                $this->addConnectionMetaPrepared($objectID, $metaCollection);
            }
        );
    }

    private function addConnectionMetaPrepared(int $objectID, MetaCollection $metaCollection): void
    {
        global $wpdb;

        do_action('wpConnections/storage/addConnectionMeta/before', $this->getClient(), $objectID, $metaCollection);
        $this->assertSitePrefix();

        foreach ($metaCollection->getIterator() as $meta) {
            /** @var Query\Meta $meta */
            $data = [
                'connection_id' => $objectID,
                'meta_key'      => $meta->getKey(),
                'meta_value'    => $meta->getValue(),
            ];

            $result = $wpdb->insert($this->fullTableName($this->meta_table), $data);
            if (false === $result) {
                throw $this->storageFailure('add connection metadata');
            }
        }

        $client = $this->getClient();
        $client->deferSuccessNotification(
            static function () use ($client, $objectID, $metaCollection): void {
                do_action(
                    'wpConnections/storage/addConnectionMeta/after',
                    $client,
                    $objectID,
                    $metaCollection,
                    []
                );
            }
        );
    }

    /**
     * Deletes meta fields from the DB.
     * Provide Query\MetaCollection with meta fields to remove.
     * Put empty Query\MetaCollection to remove all meta fields.
     *
     * @throws ConnectionWrongData
     */
    public function removeConnectionMeta(int $objectID, Query\MetaCollection $metaQuery)
    {
        global $wpdb;

        $this->assertSitePrefix();

        if (empty($objectID)) {
            throw new Exceptions\ConnectionWrongData("Object ID is empty.");
        }

        $from = 'DELETE FROM ' . $this->fullTableName($this->meta_table);
        $where = [" WHERE connection_id = {$objectID}"];
        if (! $metaQuery->isEmpty()) {
            $where [] = "AND (";

            $or = [];
            foreach ($metaQuery->getIterator() as $meta) {
                /** @var Meta $meta */
                $item = [];
                $item [] = "(";
                $item [] = $wpdb->prepare("meta_key = '%s'", $meta->getKey());
                if (! is_null($meta->getValue())) {
                    $item [] = $wpdb->prepare("AND meta_value = '%s'", $meta->getValue());
                }
                $item [] = ")";

                $or [] = implode(' ', $item);
            }
            $where [] = implode(' OR ', $or);

            $where [] = ")";
        }

        $query = $from . implode(' ', $where);

        do_action('wpConnections/storage/removeConnectionMeta/before', $this->getClient(), $objectID, $metaQuery, $query);
        $this->assertSitePrefix();

        $rowsAffected = $wpdb->query($query);
        if (false === $rowsAffected) {
            throw $this->storageFailure('remove connection metadata');
        }

        $client = $this->getClient();
        $client->deferSuccessNotification(
            static function () use ($client, $objectID, $metaQuery, $query, $rowsAffected): void {
                do_action(
                    'wpConnections/storage/removeConnectionMeta/after',
                    $client,
                    $objectID,
                    $metaQuery,
                    $query,
                    $rowsAffected
                );
            }
        );

        return $rowsAffected;
    }

    private function storageFailure(
        string $operation,
        string $databaseError = '',
        \Throwable $previous = null
    ): StorageFailure {
        if ('' === $databaseError) {
            $databaseError = $this->databaseError();
        }

        if ('' !== $databaseError) {
            $previous = new \RuntimeException($databaseError, 0, $previous);
        }

        return new StorageFailure($operation, $previous);
    }
}
