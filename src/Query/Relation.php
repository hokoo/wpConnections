<?php

namespace iTRON\wpConnections\Query;

use iTRON\wpConnections\Client;
use iTRON\wpConnections\GSInterface;

class Relation extends \iTRON\wpConnections\Abstracts\Relation
{
    use GSInterface;

    public Client $client;
}
