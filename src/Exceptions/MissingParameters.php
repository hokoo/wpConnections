<?php

namespace iTRON\wpConnections\Exceptions;

class MissingParameters extends RelationWrongData {
	private $missingParams = [];

	public function setParam( string $param ): self {
		$this->missingParams []= $param;

		return $this;
	}

	public function getParams(): array {
		return $this->missingParams;
	}
}
