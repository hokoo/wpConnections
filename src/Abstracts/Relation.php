<?php

namespace iTRON\wpConnections\Abstracts;

class Relation {

	/**
	 * @var string
	 */
	public $name = '';

	/**
	 * @var string
	 *
	 * 'from', 'to', 'both'
	 */
	protected $type;

	/**
	 * @var string
	 *
	 * '1-1', '1-m', 'm-m', 'm-1'
	 */
	protected $cardinality;

	/**
	 * Ability to make identical connections
	 * @var bool
	 */
	protected $duplicatable;

	/**
	 * Ability to make self-connections
	 * @var bool
	 */
	protected $closurable;

	/**
	 * @var string
	 */
	protected $from;

	/**
	 * @var string
	 */
	protected $to;
}
