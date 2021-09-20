<?php

namespace iTRON\wpConnections;

trait GSInterface {
	public function set( string $field, $value ): self {
		$this->$field = $value;
		return $this;
	}

	public function get( string $field ) {
		return $this->{$field} ?? null;
	}
}
