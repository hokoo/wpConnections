<?php

namespace iTRON\wpConnections;

use iTRON\wpConnections\Exceptions\ClientRegisterFail;
use iTRON\wpConnections\Exceptions\ConnectionNotFound;
use iTRON\wpConnections\Exceptions\RelationNotFound;
use iTRON\wpConnections\Exceptions\RelationWrongData;
use iTRON\wpConnections\Exceptions\MissingParameters;
use Psr\Log\LoggerInterface;

class Client
{
    private const RELATION_CARDINALITIES = [ '1-1', '1-m', 'm-1', 'm-m' ];

    private string $name;
    private Abstracts\Storage $storage;
    private RelationCollection $relations;
    private LoggerInterface $logger;
    private ConnectionEntityValidator $entityValidator;

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
        $this->entityValidator = new ConnectionEntityValidator();
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

        foreach ([ 'name', 'from', 'to' ] as $requiredParameter) {
            if (empty($relationQuery->get($requiredParameter))) {
                $missingParameters->setParam($requiredParameter);
            }
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

        if (! in_array($args['cardinality'], self::RELATION_CARDINALITIES, true)) {
            $relationWrongData = new RelationWrongData('Unknown relation cardinality: ');
            $relationWrongData->setParam((string) $args['cardinality']);
            throw $relationWrongData;
        }

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
     * Registers a client-scoped resolver before the client's first connection
     * mutation.
     *
     * @throws ClientRegisterFail
     */
    public function registerEntityResolver(EntityResolverInterface $resolver): self
    {
        $this->entityValidator->registerResolver($resolver);

        return $this;
    }

    /**
     * @internal Domain mutation entrypoints are the only callers.
     */
    public function assertConnectionEndpoints(
        Relation $relation,
        Abstracts\Connection $connection
    ): void {
        $this->entityValidator->assertEndpoints($relation, $connection);
    }

    /**
     * @internal Loads one client-owned connection and attaches domain context.
     *
     * @throws ConnectionNotFound
     */
    public function findConnection(int $connectionId): Connection
    {
        $query = new Query\Connection();
        $query->set('id', $connectionId);
        $connections = $this->hydrateConnections(
            $this->storage->findConnections($query)
        );

        if ($connections->isEmpty()) {
            throw new ConnectionNotFound();
        }

        return $connections->first();
    }

    /**
     * @internal Storage returns persistence data; the domain owns client context.
     */
    public function hydrateConnections(ConnectionCollection $connections): ConnectionCollection
    {
        foreach ($connections->getIterator() as $connection) {
            $connection->setClient($this);
        }

        return $connections;
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
