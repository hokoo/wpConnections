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
	public $type;

	/**
	 * @var string
	 *
	 * '1-1', '1-m', 'm-m', 'm-1'
	 */
    public $cardinality;

	/**
	 * Ability to make identical connections
	 * @var bool
	 */
    public $duplicatable;

	/**
	 * Ability to make self-connections
	 * @var bool
	 */
    public $closurable;

	/**
	 * @var string
	 */
    public $from;

	/**
	 * @var string
	 */
    public $to;
}
