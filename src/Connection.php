<?php

namespace iTRON\wpConnections;

class Connection extends Abstracts\Connection {
	use ClientInterface;
	use GSInterface;

	public function __construct( int $id = 0 ){
        parent::__construct();

		if ( empty ( $id ) ) return;

		$this->load();
	}

    public function __clone() {
		$this->meta = clone $this->meta;
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
		$this->meta = clone $connectionQuery->meta;
		$this->order = $connectionQuery->order;

		return $this;
	}
}
