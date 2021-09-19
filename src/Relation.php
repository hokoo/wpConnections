<?php

namespace iTRON\wpConnections;

class Relation {
	use ClientInterface;

	/**
	 * @var string
	 */
	private $name = '';

	/**
	 * @var string
	 *
	 * 'from', 'to', 'both'
	 */
	private $type;

	/**
	 * @var string
	 *
	 * '1-1', '1-m', 'm-m', 'm-1'
	 */
	private $cardinality;

	/**
	 * Ability to make identical connections
	 * @var bool
	 */
	private $duplicatable;

	/**
	 * Ability to make self-connections
	 * @var bool
	 */
	private $closurable;

	/**
	 * @var string
	 */
	private $from;

	/**
	 * @var string
	 */
	private $to;

	public function __construct(){}

	public function getName(): string {
		return $this->name;
	}

	/**
	 * Creates new connect
	 */
	public function connect(){}

	public function set( string $field, $value ): self {
		$this->$field = $value;
		return $this;
	}

	/**
	 * @param RelationTypeParam $params
	 *
	 * @return ConnectionCollection
	 */
	public function find( RelationTypeParam $params ): ConnectionCollection {
		return $this->getClient()->getStorage()->findConnections( $params );
	}

	public function detach(){}
}
