<?php

namespace iTRON\wpConnections\Abstracts;

class Connection {

	/**
	 * @var int
	 */
	protected $id;

	/**
	 * @var string
	 */
	protected $title = '';

	/**
	 * @var string
	 */
	protected $relation = '';

	/**
	 * @var int
	 */
	protected $from;

	/**
	 * @var int
	 */
	protected $to;

	/**
	 * @var array
	 */
	protected $meta;

	/**
	 * @var int
	 */
	protected $order;
}
