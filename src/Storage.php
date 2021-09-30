<?php

namespace iTRON\wpConnections;

use iTRON\wpConnections\Exceptions\ConnectionWrongData;

class Storage extends Abstracts\Storage {
	use ClientInterface;

	private $connections_table = 'post_connections_';

	private $meta_table = 'post_connections_meta_';

	/**
	 * @param Client $client wpConnections Client
	 */
	public function __construct( Client $client ) {
		$this->client     = $client;
		$postfix          = str_replace( '-', '_', sanitize_title( $client->getName() ) );
		$this->connections_table  = $this->connections_table . $postfix;
		$this->meta_table = $this->meta_table . $postfix;

		$this->init();
	}

	function get_connections_table(): string {
		return $this->connections_table;
	}

	function get_meta_table(): string {
		return $this->meta_table;
	}

	private function init() {
		scb_register_table( $this->get_connections_table() );
		scb_register_table( $this->get_meta_table() );
	}

	private function install() {
		scb_install_table( $this->get_connections_table(), "
			`ID`        bigint(20)      unsigned NOT NULL auto_increment,
			`relation`  varchar(255)    NOT NULL,
			`from`      bigint(20)      unsigned NOT NULL,
			`to`        bigint(20)      unsigned NOT NULL,
			`order`     bigint(20)      unsigned NULL default '0',
			`title`     varchar(63)     NULL default '',

			PRIMARY KEY (`ID`),
			INDEX `from` (`from`),
			INDEX `to` (`to`),
			INDEX `order` (`order`)
		" );

		scb_install_table( $this->get_meta_table(), "
			`ID`              bigint(20)      unsigned NOT NULL auto_increment,
			`connection_id`   bigint(20)      unsigned NOT NULL default '0',
			`key`             varchar(255)    NOT NULL,
			`value`           longtext        NOT NULL,
			
			PRIMARY KEY (`ID`),
			INDEX `connection_id` (`connection_id`),
			INDEX `key` (`key`)
		" );
	}

	/**
	 * Deletes connections by set of connection IDs
	 *
	 * @throws ConnectionWrongData
	 *
	 * @return int Rows number affected
	 */
	public function deleteSpecificConnections( $connectionIDs ): int {
		global $wpdb;

		$connectionIDs = $this->prepareIDs( $connectionIDs );

		// MySQL Query
		$db = $wpdb->prefix . $this->connections_table;
		$in = implode( ',', $connectionIDs );
		$query = "DELETE FROM {$db} WHERE `ID` IN {$in}";

		$wpdb->query( esc_sql( $query ) );

		return $wpdb->rows_affected;
	}

	/**
	 * Deletes connections by object ID(s).
	 *
	 * @throws ConnectionWrongData
	 *
	 * @return int Rows number affected
	 */
	public function deleteByObjectID( $objectIDs, bool $onlyFrom = false, bool $onlyTo = false ): int {
		global $wpdb;

		// Only one of direction restricts may be set true.
		if ( $onlyFrom && $onlyTo ) {
			return 0;
		}

		$in = implode( ',', $this->prepareIDs( $objectIDs ) );

		$where = [];

		if ( ! $onlyFrom ) {
			$where []= "`to` IN {$in}";
		}

		if ( ! $onlyTo ) {
			$where []= "`from` IN {$in}";
		}

		$where_str = implode( ' OR ', $where );

		$db = $wpdb->prefix . $this->connections_table;
		$query = "DELETE FROM {$db} WHERE {$where_str}";

		$wpdb->query( esc_sql( $query ) );

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
	public function deleteDirectedConnections( int $from = null, int $to = null, string $relation = '' ): int {
		global $wpdb;

		// Only exactly specified connections may be deleted.
		if ( empty( $from ) || empty( $to ) ) {
			return 0;
		}

		// MySQL Query
		$db = $wpdb->prefix . $this->get_connections_table();
		$db_meta = $wpdb->prefix . $this->get_meta_table();
		$relation_query = empty( $relation ) ? '1=1' : "`relation` LIKE '{$relation}'";

		// Get ID's
		$query_ids = "SELECT `ID` FROM {$db} WHERE {$relation_query} AND `from` = {$from} AND `to` = {$to}";
		$result_ids = $wpdb->get_results( $query_ids );
		$ids = ( is_array( $result_ids ) && ! empty( $result_ids ) ) ? array_column( $result_ids, 'ID' ) : [];

		// Nothing found.
		if ( empty( $ids ) ) return 0;

		// Delete
		$in = implode( ',', $ids );
		$query = "DELETE FROM {$db} WHERE `ID` IN ({$in})";
		$query_meta = "DELETE FROM {$db_meta} WHERE `connection_id` IN ({$in})";

		// @TODO Transaction
		$wpdb->query( esc_sql( $query_meta ) );
		$wpdb->query( esc_sql( $query ) );

		return $wpdb->rows_affected;
	}

	/**
	 * @throws ConnectionWrongData
	 *
	 * @param int|int[] $connectionIDs
	 *
	 * @return int|int[]
	 */
	protected function prepareIDs( $connectionIDs ) {
		$connectionIDs = is_numeric( $connectionIDs ) ? [ $connectionIDs ] : $connectionIDs;
		$e = new ConnectionWrongData( 'Integer or array of integer expected.' );

		if ( ! is_array( $connectionIDs ) ) {
			throw $e;
		}

		// Filter out non-numeric array items
		$connectionIDs = array_filter( $connectionIDs, function ( $item ) { return is_numeric( $item ); } );

		if ( empty( $connectionIDs ) ) {
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
	public function findConnections( Query\Connection $params ): ConnectionCollection {
		global $wpdb;

		$where = [];

		if ( $params->exists_relation() ) {
			$where []= "`relation` = '{$params->get( 'relation' )}'";
		}

		if ( $params->exists_from() ) {
			$where []= "`from` = {$params->get( 'from' )}";
		}

		if ( $params->exists_to() ) {
			$where []= "`to` = {$params->get( 'to' )}";
		}

		if ( $params->exists_both() ) {
			$where []= "( `from` = {$params->get( 'from' )} OR `to` = {$params->get( 'to' )} )";
		};

		if ( empty( $where ) ) {
			return new ConnectionCollection();
		}

		$where_str = implode( ' AND ', $where );
		$db = $wpdb->prefix . $this->connections_table;
		$query = "SELECT * FROM {$db} WHERE {$where_str}";
		$query_result = $wpdb->get_results( $query );

		return new ConnectionCollection( $query_result );
	}

	/**
	 * @throws Exceptions\ConnectionWrongData
	 *
	 * @return int Connection ID
	 */
	public function createConnection( Query\Connection $connectionQuery ): int {
		global $wpdb;

		$data = [
			'from'      => $connectionQuery->get('from'),
			'to'        => $connectionQuery->get('to'),
			'order'     => $connectionQuery->get('order'),
			'relation'  => $connectionQuery->get('relation'),
			'title'     => $connectionQuery->get('title'),
		];

		$attempt = 0;
		do {
			$result = $wpdb->insert( $wpdb->prefix . $this->connections_table, $data );

			if ( false === $result && 0 === $attempt ) {
				// Try to create tables
				$this->install();
			}

			$attempt++;
		} while ( false === $result && 1 >= $attempt );

		if ( false === $result ) {
			throw new Exceptions\ConnectionWrongData( "Database refused inserting new connection with the words: [{$wpdb->last_error}]" );
		}

		// @TODO Meta data insert

		return $wpdb->insert_id;
	}
}
