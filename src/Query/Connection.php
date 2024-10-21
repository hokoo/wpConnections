<?php

namespace iTRON\wpConnections\Query;

use iTRON\wpConnections\GSInterface;

class Connection extends \iTRON\wpConnections\Abstracts\Connection
{
    use GSInterface;

    public int $both = 0;

    public function __construct(int $from = 0, int $to = 0, int $both = 0)
    {
        parent::__construct();
        $this->from = $from;
        $this->to = $to;
        $this->both = $both;
    }

    public function exists_relation(): bool
    {
        return ! empty($this->relation);
    }

    public function exists_from(): bool
    {
        return $this->from > 0;
    }

    public function exists_to(): bool
    {
        return $this->to > 0;
    }

    public function exists_both(): bool
    {
        return $this->both > 0;
    }

    protected function getMetaCollection(): MetaCollection
    {
        return new MetaCollection();
    }
}
