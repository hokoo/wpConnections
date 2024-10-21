<?php

namespace iTRON\wpConnections;

use iTRON\wpConnections\Exceptions\ConnectionWrongData;

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
     * Saves instance to DB.
     * Cannot be used to create new instance, only to update existing.
     * This method overrides fields in a storage, not appends, and deletes all meta fields in a storage before saving.
     *
     * @throws ConnectionWrongData
     */
    public function update(): void
    {
        if (empty($this->id)) {
            throw new ConnectionWrongData('Cannot update uninitialized connection', 304);
        }

        $this->getClient()->getStorage()->updateConnection($this);

        $this->getClient()->getStorage()->removeConnectionMeta($this->id, new Query\MetaCollection());
        $this->getClient()->getStorage()->addConnectionMeta($this->id, $this->meta);
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
        $this->meta = new MetaCollection();
        $this->meta->fromArray($connectionQuery->get('meta')->toArray());
        $this->order = $connectionQuery->get('order');
        if ($connectionQuery->get('client')) {
            $this->setClient($connectionQuery->get('client'));
        }

        return $this;
    }
}
