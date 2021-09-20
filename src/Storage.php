<?php

namespace iTRON\wpConnections;

use iTRON\wpConnections\Exceptions\ConnectionWrongData;

class Storage {
	use ClientInterface;

	private $connections_table = 'post_connections_';

	private $meta_table = 'post_connections_meta_';

	/**
	 * @param Client $client P2P Client
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
		$this->install();

		scb_register_table( $this->get_connections_table() );
		scb_register_table( $this->get_meta_table() );
	}

	private function install() {
		scb_install_table( $this->get_connections_table(), "
			ID          bigint(20)      unsigned NOT NULL auto_increment,
			relation    varchar(255)    unsigned NOT NULL,
			from        bigint(20)      unsigned NOT NULL,
			to          bigint(20)      unsigned NOT NULL,
			order       bigint(20)      unsigned NOT NULL,
			title       varchar(63)     unsigned NOT NULL,
			
			PRIMARY KEY (ID),
			KEY from (from),
			KEY to (to),
			KEY order (order),
		" );

		scb_install_table( $this->get_meta_table(), "
			ID              bigint(20)      unsigned NOT NULL auto_increment,
			connection_id   bigint(20)      unsigned NOT NULL default '0',
			key             varchar(255)    default NULL,
			value           longtext,
			
			PRIMARY KEY  (ID),
			KEY connection_id (connection_id),
			KEY key (key)
		" );
	}

	/**
	 * Deletes connections by set of connection IDs
	 *
	 * @throws ConnectionWrongData
	 *
	 * @return int Rows number affected
	 */
	public function deleteConnections( $connectionIDs ): int {
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
	 * Deletes connections by set of object IDs
	 *
	 * @throws ConnectionWrongData
	 *
	 * @return int Rows number affected
	 */
	public function deleteByObjectID( $objectIDs ): int {
		global $wpdb;

		$objectIDs = $this->prepareIDs( $objectIDs );

		// MySQL Query
		$db = $wpdb->prefix . $this->connections_table;
		$in = implode( ',', $objectIDs );
		$query = "DELETE FROM {$db} WHERE `from` IN {$in} OR `to` IN {$in}";

		$wpdb->query( esc_sql( $query ) );

		return $wpdb->rows_affected;
	}

	/**
	 * @throws ConnectionWrongData
	 *
	 * @return int Rows number affected
	 */
	protected function prepareIDs( $connectionIDs ): int {
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

	public function findConnections( Query\Connection $params ): ConnectionCollection {
		global $wpdb;

		$where = '';

		if ( $params->exists_relation() ) {
			$where .= " `relation` = {$params->get( 'relation' )} AND";
		}

		if ( $params->exists_from() ) {
			$where .= " `from` = {$params->get( 'from' )} AND";
		}

		if ( $params->exists_to() ) {
			$where .= " `to` = {$params->get( 'to' )} AND";
		}

		if ( $params->exists_both() ) {
			$where = " ( `from` = {$params->get( 'from' )} OR `to` = {$params->get( 'to' )} ) AND";
		};

		$where .= ' 1=1';

		$db = $wpdb->prefix . $this->connections_table;

		$query = "SELECT * FROM {$db} WHERE {$where}";

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
		];

		$result = $wpdb->insert( $this->connections_table, $data, ['%d','%d','%d','%s'] );

		if ( false === $result ) {
			throw new Exceptions\ConnectionWrongData( 'Database refused inserting new connection.' );
		}

		return $wpdb->insert_id;
	}
}
