<?php

namespace iTRON\wpConnections;

use iTRON\wpConnections\Exceptions\ClientRegisterFail;
use iTRON\wpConnections\Exceptions\RelationNotFound;
use iTRON\wpConnections\Exceptions\RelationWrongData;
use iTRON\wpConnections\Exceptions\MissingParameters;
use Psr\Log\LoggerInterface;

class Client
{
    private string $name;
    private Abstracts\Storage $storage;
    private RelationCollection $relations;
    private LoggerInterface $logger;

    /**
     * WP user capability id that is required for performing actions with client.
     */
    public Capabilities $capabilities;

    /**
     * @throws ClientRegisterFail
     */
    public function __construct($name)
    {
        $this->name = sanitize_title($name);
        $this->init();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getStorage(): Abstracts\Storage
    {
        return $this->storage;
    }

    public function getRelations(): RelationCollection
    {
        return $this->relations;
    }

    /**
     * Sugar for $this->getRelations()->get()
     *
     * @param string $name Connection name.
     *
     * @return Relation
     * @throws RelationNotFound
     */
    public function getRelation(string $name): Relation
    {
        return $this->relations->get($name);
    }

    /**
     * @return Relation Registers new relation.
     *
     * @throws RelationWrongData
     * @throws MissingParameters
     */
    public function registerRelation(Query\Relation $relationQuery): Relation
    {
        $relationQuery->set('client', $this);

        $missingParameters = new MissingParameters();

        if (empty($relationQuery->get('name'))) {
            $missingParameters->setParam('name');
        }

        if (empty($relationQuery->get('from'))) {
            $missingParameters->setParam('from');
        }

        if (empty($relationQuery->get('name'))) {
            $missingParameters->setParam('name');
        }

        if ($missingParameters->getParams()) {
            throw $missingParameters;
        }

        $relationWrongData = new RelationWrongData('Relation has been already created. ');

        try {
            $exists = $relationQuery->client->getRelation($relationQuery->name);
        } catch (Exceptions\RelationNotFound $notFound) {
            // Relation's name is free, it's ok.
        }

        if (isset($exists) && $exists instanceof Relation) {
            $relationWrongData->setParam($relationQuery->name);
            throw $relationWrongData;
        }

        $default = [
            'type'          => 'both',
            'cardinality'   => 'm-m',
            'duplicatable'  => false,
            'closurable'    => false,
        ];

        $args = wp_parse_args($relationQuery, $default);

        $relation = new Relation();
        foreach ($args as $field => $value) {
            $relation->set($field, $value);
        }

        $this->relations->add($relation);
        return $relation;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * @throws ClientRegisterFail
     */
    private function init()
    {
        $clientDefaultCapabilities = apply_filters("wpConnections/client/{$this->getName()}/clientDefaultCapabilities", '');
        $this->capabilities = new Capabilities($clientDefaultCapabilities);
        $this->storage = Factory::getStorage($this);
        $this->logger = Factory::getLogger($this);
        $restapi = Factory::getRestApi($this);
        $restapi->init();

        $settings = new Settings();
        $settings->setLogger($this->getLogger());
        $settings->init();

        $this->relations = new RelationCollection();

        add_action('deleted_post', [ $this->storage, 'deleteByObjectID' ]);

        do_action('wpConnections/client/inited', $this);
        do_action("wpConnections/client/{$this->getName()}/inited", $this);
    }
}
