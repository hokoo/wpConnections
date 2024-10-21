<?php

namespace iTRON\wpConnections;

use iTRON\wpConnections\Exceptions\ConnectionWrongData;
use iTRON\wpConnections\Helpers\Database;

class WPStorage extends Abstracts\Storage
{
    use ClientInterface;

    public const CONNECTIONS_TABLE_PREFIX = 'post_connections_';
    public const META_TABLE_PREFIX = 'post_connections_meta_';

    private string $connections_table;
    private string $meta_table;

    /**
     * @param Client $client wpConnections Client
     */
    public function __construct(Client $client)
    {
        $this->client = $client;
        $postfix = Database::normalize_table_name($client->getName());
        $this->connections_table = self::CONNECTIONS_TABLE_PREFIX . $postfix;
        $this->meta_table = self::META_TABLE_PREFIX . $postfix;

        $this->init();
    }
    public function get_connections_table(): string
    {
        return $this->connections_table;
    }

    public function get_meta_table(): string
    {
        return $this->meta_table;
    }

    private function init()
    {
        Database::register_table($this->get_connections_table());
        Database::register_table($this->get_meta_table());

        $install_on_init = apply_filters('wpConnections/storage/installOnInit', false, $this->client);

        if (true !== $install_on_init) {
            return;
        }

        $this->install();
    }

    private function install()
    {
        Database::install_table($this->get_connections_table(), "
			`ID`        bigint(20)      unsigned NOT NULL auto_increment,
			`relation`  varchar(255)    NOT NULL,
			`from`      bigint(20)      unsigned NOT NULL,
			`to`        bigint(20)      unsigned NOT NULL,
			`order`     bigint(20)      unsigned NULL default '0',
			`title`     varchar(63)     NULL default '',

			PRIMARY KEY (`ID`),
			INDEX `from` (`from`),
			INDEX `to` (`to`),
			INDEX `order` (`order`),
			INDEX `relation` (`relation`)
		");

        Database::install_table($this->get_meta_table(), "
			`meta_id`         bigint(20)      unsigned NOT NULL auto_increment,
			`connection_id`   bigint(20)      unsigned NOT NULL default '0',
			`meta_key`        varchar(255)    NOT NULL,
			`meta_value`      longtext        NOT NULL,
			
			PRIMARY KEY (`meta_id`),
			INDEX `connection_id` (`connection_id`),
			INDEX `key` (`meta_key`)
		");
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

        do_action('wpConnections/storage/deleteSpecificConnections', $this->getClient(), $connectionIDs);
        do_action("wpConnections/client/{$this->getClient()->getName()}/storage/deleteSpecificConnections", $connectionIDs);

        $connectionIDs = $this->prepareIDs($connectionIDs);

        // MySQL Query
        $db = $wpdb->prefix . $this->get_connections_table();
        $db_meta = $wpdb->prefix . $this->get_meta_table();
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

        do_action('wpConnections/storage/deleteByObjectID', $this->getClient(), $objectIDs, $relation, $onlyFrom, $onlyTo);
        do_action("wpConnections/client/{$this->getClient()->getName()}/storage/deleteByObjectID", $objectIDs, $relation, $onlyFrom, $onlyTo);

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
        $db = $wpdb->prefix . $this->get_connections_table();
        $db_meta = $wpdb->prefix . $this->get_meta_table();

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

        do_action('wpConnections/storage/deleteDirectedConnections', $this->getClient(), $from, $to, $relation);
        do_action("wpConnections/client/{$this->getClient()->getName()}/storage/deleteDirectedConnections", $from, $to, $relation);

        // Only exactly specified connections may be deleted.
        if (empty($from) || empty($to)) {
            return 0;
        }

        // MySQL Query
        $db = $wpdb->prefix . $this->get_connections_table();
        $db_meta = $wpdb->prefix . $this->get_meta_table();
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

        $where = [];

        if (is_numeric($id = $params->get('id')) && ! empty($id)) {
            $_where = "c.ID = {$id}";
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
                $where [] = $wpdb->prepare("( c.from = $1%d OR c.to = $1%d )", $params->get('both'));
            }
        }

        if (empty($where)) {
            return new ConnectionCollection();
        }

        $where_str = implode(' AND ', $where);
        $db = $wpdb->prefix . $this->get_connections_table();
        $db_meta = $wpdb->prefix . $this->get_meta_table();
        $query = "SELECT c.*, m.* FROM {$db} c LEFT JOIN {$db_meta} m ON c.ID = m.connection_id WHERE {$where_str}";
        $query_result = $wpdb->get_results($query);

        do_action('wpConnections/storage/findConnections/dbQuery', $query, $query_result);

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
            $item[ 'client' ] = $this->getClient();

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
            $suppress = $wpdb->suppress_errors();
            $result = $wpdb->insert($wpdb->prefix . $this->get_connections_table(), $data);
            $wpdb->suppress_errors($suppress);
            do_action('iTRON/wpConnections/storage/createConnection/attempt/result', $result, $wpdb->last_error);

            if (false === $result && 0 === $attempt) {
                // Try to create tables
                $this->install();
            }

            $attempt++;
        } while (false === $result && 1 >= $attempt);

        if (false === $result) {
            throw new Exceptions\ConnectionWrongData("Database refused inserting new connection with the words: [{$wpdb->last_error}]");
        }

        $connection_id = $wpdb->insert_id;
        $connectionQuery->set('id', $connection_id);

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

        $where = ['ID' => $connection->id];
        $update = [
            'from'      => $connection->from,
            'to'        => $connection->to,
            'order'     => $connection->order,
            'relation'  => $connection->relation,
            'title'     => $connection->title,
        ];

        return $wpdb->update($wpdb->prefix . $this->get_connections_table(), $update, $where);
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

        if ($metaCollection->isEmpty()) {
            throw new Exceptions\ConnectionWrongData("Meta object is empty.");
        }

        if (empty($objectID)) {
            throw new Exceptions\ConnectionWrongData("Object ID is empty.");
        }

        do_action('wpConnections/storage/addConnectionMeta/before', $this->getClient(), $objectID, $metaCollection);

        $errors = [];
        foreach ($metaCollection->getIterator() as $meta) {
            /** @var Query\Meta $meta */
            $data = [
                'connection_id' => $objectID,
                'meta_key'      => $meta->getKey(),
                'meta_value'    => $meta->getValue(),
            ];

            $result = $wpdb->insert($wpdb->prefix . $this->get_meta_table(), $data);
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

        if (empty($objectID)) {
            throw new Exceptions\ConnectionWrongData("Object ID is empty.");
        }

        $from = "DELETE FROM {$wpdb->prefix}{$this->get_meta_table()}";
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

        $rowsAffected = $wpdb->query($query);

        do_action('wpConnections/storage/removeConnectionMeta/after', $this->getClient(), $objectID, $metaQuery, $query, $rowsAffected);

        return $rowsAffected;
    }
}
