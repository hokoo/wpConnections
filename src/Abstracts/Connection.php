<?php

namespace iTRON\wpConnections\Abstracts;

class Connection {

    public int $id = 0;
    protected string $title = '';
    public string $relation = '';
    public int $from;
    public int $to;
    public int $order;

    /**
     * @var array
     *      'meta_id'           int         Meta ID, autoincrement.
     *      'connection_id'     int         Connection ID related with.
     *      'meta_key'          string
     *      'meta_value'        string
     *
     */
    public array $meta;
}
