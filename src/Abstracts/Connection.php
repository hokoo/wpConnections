<?php

namespace iTRON\wpConnections\Abstracts;

class Connection {

    /**
     * @var int
     */
    public $id;

    /**
     * @var string
     */
    protected $title = '';

    /**
     * @var string
     */
    public $relation = '';

    /**
     * @var int
     */
    public $from;

    /**
     * @var int
     */
    public $to;

    /**
     * @var array
     *      'meta_id'           int         Meta ID, autoincrement.
     *      'connection_id'     int         Connection ID related with.
     *      'meta_key'          string
     *      'meta_value'        string
     *
     */
    public $meta;

    /**
     * @var int
     */
    public $order;
}
