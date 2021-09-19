<?php

namespace iTRON\wpConnections;

trait ClientInterface{
	/**
	 * @var Client
	 */
	private $client;

	function getClient(): Client {
		return $this->client;
	}

	function setClient( Client $client ): self {
		$this->client = $client;
		return $this;
	}
}
