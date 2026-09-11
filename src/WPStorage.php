<?php

namespace iTRON\wpConnections;

use iTRON\wpConnections\Exceptions\ConnectionWrongData;
use iTRON\wpConnections\Exceptions\ClientRegisterFail;
use iTRON\wpConnections\Helpers\Database;

class WPStorage extends Abstracts\Storage
{
    use ClientInterface;

    public const CONNECTIONS_TABLE_PREFIX = 'post_connections_';
    public const META_TABLE_PREFIX = 'post_connections_meta_';

    private const OWNERSHIP_OPTION_PREFIX = 'wpconnections_storage_owner_';
    private const OWNERSHIP_VERSION = 1;

    private string $connections_table;
    private string $meta_table;
    private string $postfix;
    private string $site_prefix;

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

        $this->install();
    }

    private function install()
    {
        $this->assertSitePrefix();
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
            "
        );

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
            "
        );
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

        if ($connectionsExists !== $metaExists) {
            throw new ClientRegisterFail('Client table ownership is ambiguous; explicit migration is required.');
        }

        if (null !== $record) {
            $this->assertOwnershipRecord($record);

            if (
                $connectionsExists &&
                (! $this->hasExpectedSchema(
                    $this->site_prefix . $this->connections_table,
                    [ 'ID', 'relation', 'from', 'to', 'order', 'title' ]
                ) || ! $this->hasExpectedSchema(
                    $this->site_prefix . $this->meta_table,
                    [ 'meta_id', 'connection_id', 'meta_key', 'meta_value' ]
                ))
            ) {
                throw new ClientRegisterFail('Client table ownership is ambiguous; explicit migration is required.');
            }

            return;
        }

        if ($connectionsExists) {
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

        return 1 === (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
                $table
            )
        );
    }

    private function hasExpectedSchema(string $table, array $expectedColumns): bool
    {
        global $wpdb;

        $escapedTable = str_replace('`', '``', $table);
        $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$escapedTable}`");

        return $expectedColumns === $columns;
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
        global $wpdb;

        $this->assertSitePrefix();

        do_action('wpConnections/storage/deleteSpecificConnections', $this->getClient(), $connectionIDs);
        do_action("wpConnections/client/{$this->getClient()->getName()}/storage/deleteSpecificConnections", $connectionIDs);
        $this->assertSitePrefix();

        $connectionIDs = $this->prepareIDs($connectionIDs);

        // MySQL Query
        $db = $this->fullTableName($this->connections_table);
        $db_meta = $this->fullTableName($this->meta_table);
        $in = implode(',', $connectionIDs);

        $query = "DELETE FROM {$db} WHERE `ID` IN ({$in})";
        $query_meta = "DELETE FROM {$db_meta} WHERE `connection_id` IN ({$in})";

        $wpdb->query(esc_sql($query_meta));
        $wpdb->query(esc_sql($query));

        do_action('wpConnections/storage/deletedSpecificConnections', $this->getClient(), $connectionIDs, $wpdb->rows_affected);
        do_action("wpConnections/client/{$this->getClient()->getName()}/storage/deletedSpecificConnections", $connectionIDs, $wpdb->rows_affected);

        return $wpdb->rows_affected;
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
        global $wpdb;

        if ($this->isStaleDeletedPostContext()) {
            return 0;
        }

        $this->assertSitePrefix();

        do_action('wpConnections/storage/deleteByObjectID', $this->getClient(), $objectIDs, $relation, $onlyFrom, $onlyTo);
        do_action("wpConnections/client/{$this->getClient()->getName()}/storage/deleteByObjectID", $objectIDs, $relation, $onlyFrom, $onlyTo);
        $this->assertSitePrefix();

        // Only one of direction restricts may be set true.
        if ($onlyFrom && $onlyTo) {
            return 0;
        }

        $in = implode(',', $this->prepareIDs($objectIDs));

        $where = [];

        if (! $onlyFrom) {
            $where [] = "`to` IN ({$in})";
        }

        if (! $onlyTo) {
            $where [] = "`from` IN ({$in})";
        }

        $where_str = implode(' OR ', $where);

        $relation_query = empty($relation) ? '1=1' : "`relation` LIKE '{$relation}'";
        $db = $this->fullTableName($this->connections_table);
        $db_meta = $this->fullTableName($this->meta_table);

        // Get ID's
        $query_ids = "SELECT `ID` FROM {$db} WHERE {$relation_query} AND ({$where_str})";
        $result_ids = $wpdb->get_results($query_ids);
        $ids = ( is_array($result_ids) && ! empty($result_ids) ) ? array_column($result_ids, 'ID') : [];

        // Nothing found.
        if (empty($ids)) {
            return 0;
        }

        // Delete
        $in = implode(',', $ids);
        $query_meta = "DELETE FROM {$db_meta} WHERE `connection_id` IN ({$in})";
        $query = "DELETE FROM {$db} WHERE `ID` IN ({$in})";

        $wpdb->query(esc_sql($query_meta));
        $wpdb->query(esc_sql($query));

        do_action('wpConnections/storage/deletedByObjectID', $this->getClient(), $ids);
        do_action("wpConnections/client/{$this->getClient()->getName()}/storage/deletedByObjectID", $ids);

        return $wpdb->rows_affected;
    }

    /**
     * Deletes exactly specified connections.
     * Able to erase multiple connections (e.g. if duplicatable is set true)
     *
     * @param int|null $from    `from` object
     * @param int|null $to      `to` object
     * @param string $relation  Relation name. Default all relations
     *
     * @return int              Rows number affected.
     */
    public function deleteDirectedConnections(int $from = null, int $to = null, string $relation = ''): int
    {
        global $wpdb;

        $this->assertSitePrefix();

        do_action('wpConnections/storage/deleteDirectedConnections', $this->getClient(), $from, $to, $relation);
        do_action("wpConnections/client/{$this->getClient()->getName()}/storage/deleteDirectedConnections", $from, $to, $relation);
        $this->assertSitePrefix();

        // Only exactly specified connections may be deleted.
        if (empty($from) || empty($to)) {
            return 0;
        }

        // MySQL Query
        $db = $this->fullTableName($this->connections_table);
        $db_meta = $this->fullTableName($this->meta_table);
        $relation_query = empty($relation) ? '1=1' : "`relation` LIKE '{$relation}'";

        // Get ID's
        $query_ids = "SELECT `ID` FROM {$db} WHERE {$relation_query} AND `from` = {$from} AND `to` = {$to}";
        $result_ids = $wpdb->get_results($query_ids);
        $ids = ( is_array($result_ids) && ! empty($result_ids) ) ? array_column($result_ids, 'ID') : [];

        // Nothing found.
        if (empty($ids)) {
            return 0;
        }

        // Delete
        $in = implode(',', $ids);
        $query = "DELETE FROM {$db} WHERE `ID` IN ({$in})";
        $query_meta = "DELETE FROM {$db_meta} WHERE `connection_id` IN ({$in})";

        // @TODO Transaction
        $wpdb->query(esc_sql($query_meta));
        $wpdb->query(esc_sql($query));

        do_action('wpConnections/storage/deletedDirectedConnections', $this->getClient(), $ids);
        do_action("wpConnections/client/{$this->getClient()->getName()}/storage/deletedDirectedConnections", $ids);

        return $wpdb->rows_affected;
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
        $connectionIDs = is_numeric($connectionIDs) ? [ $connectionIDs ] : $connectionIDs;
        $e = new ConnectionWrongData('Integer or array of integer expected.');

        if (! is_array($connectionIDs)) {
            throw $e;
        }

        // Filter out non-numeric array items
        $connectionIDs = array_filter($connectionIDs, function ($item) {
            return is_numeric($item);
        });

        if (empty($connectionIDs)) {
            throw $e;
        }

        return $connectionIDs;
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
        global $wpdb;

        $this->assertSitePrefix();
        $data = [
            'from'      => $connectionQuery->get('from'),
            'to'        => $connectionQuery->get('to'),
            'order'     => $connectionQuery->get('order') ?? 0,
            'relation'  => $connectionQuery->get('relation'),
            'title'     => $connectionQuery->get('title'),
        ];

        $attempt = 0;
        do {
            // Suppress errors when table does not exist.
            do_action('iTRON/wpConnections/storage/createConnection/attempt', $attempt);
            $this->assertSitePrefix();
            $suppress = $wpdb->suppress_errors();
            $result = $wpdb->insert($this->fullTableName($this->connections_table), $data);
            $wpdb->suppress_errors($suppress);
            do_action('iTRON/wpConnections/storage/createConnection/attempt/result', $result, $wpdb->last_error);

            if (false === $result && 0 === $attempt) {
                // Try to create tables
                $this->assertSitePrefix();
                $this->install();
            }

            $attempt++;
        } while (false === $result && 1 >= $attempt);

        if (false === $result) {
            throw new Exceptions\ConnectionWrongData("Database refused inserting new connection with the words: [{$wpdb->last_error}]");
        }

        $connection_id = $wpdb->insert_id;

        // Insert meta data.
        $metaQuery = $connectionQuery->get('meta');
        /** @var Query\MetaCollection $metaQuery */
        if (! $metaQuery->isEmpty()) {
            $this->addConnectionMeta($connection_id, $metaQuery);
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

        return $wpdb->update($this->fullTableName($this->connections_table), $update, $where);
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
        global $wpdb;

        $this->assertSitePrefix();

        if ($metaCollection->isEmpty()) {
            throw new Exceptions\ConnectionWrongData("Meta object is empty.");
        }

        if (empty($objectID)) {
            throw new Exceptions\ConnectionWrongData("Object ID is empty.");
        }

        do_action('wpConnections/storage/addConnectionMeta/before', $this->getClient(), $objectID, $metaCollection);
        $this->assertSitePrefix();

        $errors = [];
        foreach ($metaCollection->getIterator() as $meta) {
            /** @var Query\Meta $meta */
            $data = [
                'connection_id' => $objectID,
                'meta_key'      => $meta->getKey(),
                'meta_value'    => $meta->getValue(),
            ];

            $result = $wpdb->insert($this->fullTableName($this->meta_table), $data);
            if (false === $result) {
                $errors [] = $wpdb->last_error;
            }
        }

        do_action('wpConnections/storage/addConnectionMeta/after', $this->getClient(), $objectID, $metaCollection, $errors);

        if ($errors) {
            $errors = implode('; ', $errors);
            throw new Exceptions\ConnectionWrongData("Database refused inserting new connection meta data with the words: [{$errors}]");
        }
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

        do_action('wpConnections/storage/removeConnectionMeta/after', $this->getClient(), $objectID, $metaQuery, $query, $rowsAffected);

        return $rowsAffected;
    }
}
