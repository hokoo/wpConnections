<?php

namespace iTRON\wpConnections\Exceptions;

use Throwable;

class MissingParameters extends Exception {
	private $missingParams = [];

	public function __construct( $message = 'Missing required fields: ', $code = 4, Throwable $previous = null ) {
		parent::__construct( $message, $code, $previous );
	}

	public function setParam( string $param ): self {
		$this->missingParams []= $param;
        $this->setMessage( $param . ' ' );

		return $this;
	}

	public function getParams(): array {
		return $this->missingParams;
	}
}
