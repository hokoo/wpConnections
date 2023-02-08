<?php

namespace iTRON\wpConnections;

trait ClientInterface{
	private Client $client;

	function getClient(): Client {
		return $this->client;
	}

	function setClient( Client $client ): self {
		$this->client = $client;
		return $this;
	}
}
