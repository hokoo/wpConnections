<?php

namespace iTRON\wpConnections\Abstracts;

use iTRON\wpConnections\MetaCollection;

class Connection {

    public int $id = 0;
    protected string $title = '';
    public string $relation = '';
    public int $from;
    public int $to;
    public int $order;
    public MetaCollection $meta;

    public function __construct() {
        $this->meta = new MetaCollection();
    }
}
