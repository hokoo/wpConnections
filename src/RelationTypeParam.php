<?php

namespace iTRON\wpConnections;

class RelationTypeParam {

	/**
	 * @var int
	 */
	public $from = 0;

	/**
	 * @var int
	 */
	public $to = 0;

	/**
	 * @var int
	 */
	public $both = 0;

	public function __construct( int $from = 0, int $to = 0, int $both = 0 ){
		$this->from = $from;
		$this->to = $to;
		$this->both = $both;
	}

	public function exists_from(): bool {
		return $this->from > 0;
	}

	public function exists_to(): bool {
		return $this->to > 0;
	}

	public function exists_both(): bool {
		return $this->both > 0;
	}
}
