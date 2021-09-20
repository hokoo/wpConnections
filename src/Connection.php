<?php

namespace iTRON\wpConnections;

class Connection {
	use ClientInterface;

	/**
	 * @var int
	 */
	private $id;

	/**
	 * @var string
	 */
	private $title = '';

	/**
	 * @var string
	 */
	private $relation = '';

	/**
	 * @var int
	 */
	private $from;

	/**
	 * @var int
	 */
	private $to;

	/**
	 * @var array
	 */
	private $meta;

	/**
	 * @var int
	 */
	private $order;

	public function __construct( int $id = 0 ){
		if ( empty ( $id ) ) return;

		$this->load();
	}

	public function set( string $field, $value ): self {
		$this->$field = $value;
		return $this;
	}

	/**
	 * Saves instance to DB
	 */
	public function save(){}

	/**
	 * Loads existing instance from DB
	 */
	public function load(){}
}
