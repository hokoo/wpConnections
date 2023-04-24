<?php

namespace iTRON\wpConnections\Query;

use iTRON\wpConnections\Abstracts\IQuery;
use iTRON\wpConnections\GSInterface;
use iTRON\wpConnections\IQueryTrait;

class Meta extends \iTRON\wpConnections\Abstracts\Meta implements IQuery
{
    use IQueryTrait;
    use GSInterface;
}
