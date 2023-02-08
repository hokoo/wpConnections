<?php

namespace iTRON\wpConnections\Abstracts;

class Relation {

	public string $name = '';
    public string $from;
    public string $to;

	/**
	 * 'from', 'to', 'both'
	 */
	public string $type;

	/**
	 * '1-1', '1-m', 'm-m', 'm-1'
	 */
    public string $cardinality;

	/**
	 * Ability to make identical connections
	 */
    public bool $duplicatable;

	/**
	 * Ability to make self-connections
	 */
    public bool $closurable;
}
