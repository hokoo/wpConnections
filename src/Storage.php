<?php

namespace iTRON\wpConnections;

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

		add_action( 'deleted_post', [ $this, 'deleted_object' ] );
		add_action( 'deleted_user', [ $this, 'deleted_object' ] );
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

	public function deleted_object() {}

	public function findConnections( RelationTypeParam $params ): ConnectionCollection {
		global $wpdb;

		$where = '';

		if ( $params->exists_from() ) {
			$where .= " `from` = {$params->from} AND";
		}

		if ( $params->exists_to() ) {
			$where .= " `to` = {$params->to} AND";
		}

		if ( $params->exists_both() ) {
			$where = " ( `from` = {$params->from} OR `to` = {$params->to} ) AND";
		};

		$where .= ' 1=1';

		$db = $wpdb->prefix . $this->connections_table;

		$query = "SELECT * FROM {$db} WHERE {$where}";

		$query_result = $wpdb->get_results( $query );

		return new ConnectionCollection( $query_result );
	}
}
