<?php

namespace iTRON\wpConnections;

class Connection extends Abstracts\Connection
{
    use ClientInterface;

    public function __construct(Query\Connection $connectionQuery)
    {
        parent::__construct();

        $this->loadFromQuery($connectionQuery);
    }

    public function __clone()
    {
        $this->meta = clone $this->meta;
    }

    /**
     * Saves instance to DB
     */
    public function save()
    {
    }

    /**
     * Loads existing instance from DB
     */
    public function load()
    {
    }

    protected function loadFromQuery(Query\Connection $connectionQuery): Connection
    {
        $this->id = $connectionQuery->get('id');
        $this->title = $connectionQuery->get('title');
        $this->relation = $connectionQuery->get('relation');
        $this->from = $connectionQuery->get('from');
        $this->to = $connectionQuery->get('to');
        $this->meta = clone $connectionQuery->get('meta');
        $this->order = $connectionQuery->get('order');

        return $this;
    }
}
