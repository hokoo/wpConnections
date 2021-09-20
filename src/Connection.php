<?php

namespace iTRON\wpConnections;

class Connection extends Abstracts\Connection {
	use ClientInterface;
	use GSInterface;

	public function __construct( int $id = 0 ){
		if ( empty ( $id ) ) return;

		$this->load();
	}

	/**
	 * Saves instance to DB
	 */
	public function save(){}

	/**
	 * Loads existing instance from DB
	 */
	public function load(){}

	public function loadFromQuery( Query\Connection $connectionQuery ): Connection {
		$this->id = $connectionQuery->id;
		$this->title = $connectionQuery->title;
		$this->relation = $connectionQuery->relation;
		$this->from = $connectionQuery->from;
		$this->to = $connectionQuery->to;
		$this->meta = $connectionQuery->meta;
		$this->order = $connectionQuery->order;

		return $this;
	}
}
