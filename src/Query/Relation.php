<?php

namespace iTRON\wpConnections\Query;

use iTRON\wpConnections\Client;

class Relation extends \iTRON\wpConnections\Abstracts\Relation {
	use \iTRON\wpConnections\GSInterface;

	/**
	 * @var Client
	 */
	public $client;
}
