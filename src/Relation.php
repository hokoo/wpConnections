<?php

namespace iTRON\wpConnections;

use iTRON\wpConnections\Exceptions\ConnectionWrongData;
use iTRON\wpConnections\Exceptions\ConnectionNotFound;
use iTRON\wpConnections\Exceptions\ConnectionRelationMismatch;
use iTRON\wpConnections\Internal\ConnectionIdNormalizer;
use iTRON\wpConnections\Internal\PersistableMetadataValidator;

class Relation extends Abstracts\Relation
{
    use CardinalityValidation;
    use ClientInterface;
    use GSInterface;

    public function __construct()
    {
    }

    /**
     * Creates new connect
     *
     * @param Query\Connection $connectionQuery
     *
     * @return Connection
     * @throws Exceptions\MissingParameters
     * @throws Exceptions\ConnectionWrongData
     */
    public function createConnection(Query\Connection $connectionQuery): Connection
    {
        // Required fields
        $missingParameters = new Exceptions\MissingParameters();

        foreach ([ 'from', 'to' ] as $requiredParameter) {
            if (empty($connectionQuery->get($requiredParameter))) {
                $missingParameters->setParam($requiredParameter);
            }
        }

        if ($missingParameters->getParams()) {
            throw $missingParameters;
        }

        $this->getClient()->assertConnectionEndpoints($this, $connectionQuery);
        $this->assertConnectionInvariants($connectionQuery);

        if (! $connectionQuery->isProvided('order')) {
            $connectionQuery->set('order', 0);
        }

        // Create connection
        $connectionQuery->set('relation', $this->name);

        $validatedRelation = $connectionQuery->relation;
        $validatedFrom = $connectionQuery->from;
        $validatedTo = $connectionQuery->to;

        do_action('wpConnections/relation/creating', $connectionQuery);

        if ($validatedRelation !== $connectionQuery->relation) {
            throw new ConnectionRelationMismatch(
                $validatedRelation,
                $connectionQuery->relation
            );
        }

        if (
            $validatedFrom !== $connectionQuery->from
            || $validatedTo !== $connectionQuery->to
        ) {
            $this->getClient()->assertConnectionEndpoints($this, $connectionQuery);
            $this->assertConnectionInvariants($connectionQuery);
        }

        $this->assertPersistableOrder($connectionQuery);
        PersistableMetadataValidator::assertValid($connectionQuery->meta);

        $client = $this->getClient();
        $create = function () use ($client, $connectionQuery): Connection {
            $connectionId = $client->getStorage()->createConnection($connectionQuery);
            $connectionQuery->set('id', $connectionId);

            $connection = new Connection($connectionQuery);
            $connection->setClient($client);
            $client->deferSuccessNotification(
                static function () use ($connection): void {
                    do_action('wpConnections/relation/created', $connection);
                }
            );

            return $connection;
        };

        if ($connectionQuery->get('meta')->isEmpty()) {
            return $create();
        }

        return $client->executeAtomicMutation($create, true);
    }

    public function updateConnection(Query\Connection $connectionQuery): bool
    {
        if (empty($connectionQuery->get('id'))) {
            throw new ConnectionWrongData('Cannot update uninitialized connection', 304);
        }

        $persisted = $this->getClient()->findConnection((int) $connectionQuery->get('id'));
        if ($persisted->relation !== $this->name) {
            throw new ConnectionRelationMismatch($persisted->relation, $this->name);
        }

        $candidate = clone $persisted;
        foreach ([ 'from', 'to', 'title', 'order' ] as $field) {
            if ($connectionQuery->isProvided($field)) {
                $candidate->{$field} = $connectionQuery->get($field);
            }
        }

        $this->assertUpdateCandidate($candidate);

        return $this->getClient()->getStorage()->updateConnection($candidate);
    }

    /**
     * @throws ConnectionWrongData
     */
    public function removeConnectionMeta(Query\Connection $connectionQuery): int
    {
        $rowsAffected = $this->getClient()->getStorage()->removeConnectionMeta($connectionQuery->get('id'), $connectionQuery->get('meta'));

        return (int) $rowsAffected;
    }

    /**
     * Detaches connection.
     * Able to detach multiple connections if $connectionQuery->id are not set.
     *
     * @return int Connections number detached.
     */
    public function detachConnections(Query\Connection $connectionQuery): int
    {
        // Detach one specific connection. Lower-priority selectors are ignored.
        if ($connectionQuery->isProvided('id')) {
            $connectionID = ConnectionIdNormalizer::one($connectionQuery->getProvidedValue('id'));

            return $this->executeAtomicDelete(
                function () use ($connectionID): int {
                    return $this->detachSpecificConnection($connectionID);
                }
            );
        }

        // Detach any connection with $connectionQuery->both as object ID.
        if ($connectionQuery->isProvided('both')) {
            $both = ConnectionIdNormalizer::one($connectionQuery->getProvidedValue('both'));
            return $this->executeAtomicDelete(
                function () use ($both): int {
                    return $this->getClient()->getStorage()->deleteByObjectID($both, $this->name);
                }
            );
        }

        $fromProvided = $connectionQuery->isProvided('from');
        $toProvided = $connectionQuery->isProvided('to');

        // Detach directed connection(s).
        if ($fromProvided && $toProvided) {
            $from = ConnectionIdNormalizer::one($connectionQuery->getProvidedValue('from'));
            $to = ConnectionIdNormalizer::one($connectionQuery->getProvidedValue('to'));
            return $this->executeAtomicDelete(
                function () use ($from, $to): int {
                    return $this->getClient()->getStorage()->deleteDirectedConnections(
                        $from,
                        $to,
                        $this->name
                    );
                }
            );
        }

        // Detach `from` directed connections.
        if ($fromProvided) {
            $from = ConnectionIdNormalizer::one($connectionQuery->getProvidedValue('from'));
            return $this->executeAtomicDelete(
                function () use ($from): int {
                    return $this->getClient()->getStorage()->deleteByObjectID(
                        $from,
                        $this->name,
                        true
                    );
                }
            );
        }

        // Detach `to` directed connections.
        if ($toProvided) {
            $to = ConnectionIdNormalizer::one($connectionQuery->getProvidedValue('to'));
            return $this->executeAtomicDelete(
                function () use ($to): int {
                    return $this->getClient()->getStorage()->deleteByObjectID(
                        $to,
                        $this->name,
                        false,
                        true
                    );
                }
            );
        }

        throw new ConnectionWrongData('A connection delete selector is required.');
    }

    private function detachSpecificConnection(int $connectionID): int
    {
        $storage = $this->getClient()->getStorage();
        if ($storage instanceof RelationScopedDeleteStorageInterface) {
            if (! $storage->lockConnectionForDelete($connectionID, $this->name)) {
                return 0;
            }

            return $storage->deleteSpecificConnections($connectionID);
        }

        try {
            $connection = $this->getClient()->findConnection($connectionID);
        } catch (ConnectionNotFound $exception) {
            return 0;
        }

        if ($this->name !== $connection->relation) {
            return 0;
        }

        return $storage->deleteSpecificConnections($connectionID);
    }

    private function executeAtomicDelete(callable $delete): int
    {
        return (int) $this->getClient()->executeAtomicMutation($delete);
    }

    /**
     * @param Query\Connection|null $connectionQuery
     *
     * @return ConnectionCollection
     */
    public function findConnections(Query\Connection $connectionQuery = null): ConnectionCollection
    {
        $connectionQuery = $connectionQuery ?? new Query\Connection();
        $connectionQuery->set('relation', $this->name);

        return $this->getClient()->hydrateConnections(
            $this->getClient()->getStorage()->findConnections($connectionQuery)
        );
    }

    public function hasConnectionID(int $connectionID): bool
    {
        $connectionQuery = $connectionQuery ?? new Query\Connection();
        $connectionQuery->set('id', $connectionID);
        return ! $this->findConnections($connectionQuery)->isEmpty();
    }

    /**
     * @internal Shared by both supported update entrypoints.
     */
    public function assertUpdateCandidate(Abstracts\Connection $connection): void
    {
        $this->assertPersistableOrder($connection);
        $this->getClient()->assertConnectionEndpoints($this, $connection);
        $this->assertConnectionInvariants($connection);
    }

    private function assertPersistableOrder(Abstracts\Connection $connection): void
    {
        if (null === $connection->order || 0 > $connection->order) {
            throw new ConnectionWrongData('Connection order must be a non-negative integer.');
        }
    }

    private function assertConnectionInvariants(Abstracts\Connection $connection): void
    {
        if (! $this->closurable && $connection->from === $connection->to) {
            throw new Exceptions\ConnectionWrongData('Closurable not allowed by relation settings.', 301);
        }

        if (! $this->duplicatable) {
            $query = new Query\Connection($connection->from, $connection->to);
            $duplicates = $this->findConnections($query);

            foreach ($duplicates->getIterator() as $duplicate) {
                if ((int) $connection->id === (int) $duplicate->id) {
                    continue;
                }

                throw new Exceptions\ConnectionWrongData('Duplicatable violation.', 303);
            }
        }

        $this->assertCardinality($this, $connection);
    }
}
