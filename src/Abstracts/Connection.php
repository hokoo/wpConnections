<?php

namespace iTRON\wpConnections\Abstracts;

class Connection {

    /**
     * @var int
     */
    protected $id;

    /**
     * @var string
     */
    protected $title = '';

    /**
     * @var string
     */
    protected $relation = '';

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
