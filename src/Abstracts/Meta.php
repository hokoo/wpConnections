<?php

namespace iTRON\wpConnections\Abstracts;

abstract class Meta implements IArrayConvertable {
	public string $key;
	public $value;

	public function __construct( string $key = '', $value = null ) {
		if ( strlen( $key ) ) {
			$this->key = $key;
			$this->value = $value;
		}
	}

	/**
	 * @return mixed
	 */
	public function get_value() {
		return $this->value;
	}

	/**
	 * @param mixed $value
	 */
	public function set_value( $value ): void {
		$this->value = $value;
	}

	/**
	 * @return string
	 */
	public function get_key(): string {
		return $this->key;
	}

	/**
	 * @param string $key
	 */
	public function set_key( string $key ): void {
		$this->key = $key;
	}

    public function toArray(): array {
        return [
            'key'   => $this->get_key(),
            'value' => $this->get_value(),
        ];
    }
}
