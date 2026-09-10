<?php

namespace iTRON\wpConnections;

use iTRON\wpConnections\Exceptions\ConnectionWrongData;
use iTRON\wpConnections\Exceptions\ConnectionRelationMismatch;

class Relation extends Abstracts\Relation
{
    use CardinalityValidation;
    use ClientInterface;
    use GSInterface;

    public function __construct()
    {
    }

    /**
     * @TODO Apply transactions.
     *
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

        // Create connection
        $connectionQuery->set('relation', $this->name);

        do_action('wpConnections/relation/creating', $connectionQuery);

        $connectionId = $this->getClient()->getStorage()->createConnection($connectionQuery);
        $connectionQuery->set('id', $connectionId);

        $connection = new Connection($connectionQuery);
        $connection->setClient($this->getClient());

        do_action('wpConnections/relation/created', $connection);

        return $connection;
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

        try {
            // Detach specific connection.
            if (! empty($connectionQuery->get('id'))) {
                return $this->getClient()->getStorage()->deleteSpecificConnections($connectionQuery->get('id'));
            }

            // Detach any connection with $connectionQuery->both as object ID.
            if (! empty($connectionQuery->get('both'))) {
                return $this->getClient()->getStorage()->deleteByObjectID($connectionQuery->get('both'), $this->name);
            }

            // Detach directed connection(s).
            if (! empty($connectionQuery->get('from')) && ! empty($connectionQuery->get('to'))) {
                return $this->getClient()->getStorage()->deleteDirectedConnections($connectionQuery->get('from'), $connectionQuery->get('to'), $this->name);
            }

            // Detach `from` directed connections.
            if (! empty($connectionQuery->get('from'))) {
                return $this->getClient()->getStorage()->deleteByObjectID($connectionQuery->get('from'), $this->name, true);
            }

            // Detach `to` directed connections.
            if (! empty($connectionQuery->get('to'))) {
                return $this->getClient()->getStorage()->deleteByObjectID($connectionQuery->get('to'), $this->name, false, true);
            }
        } catch (ConnectionWrongData $e) {
            // There are no ideas what went wrong.
            return 0;
        }

        // Seems, we have received empty query.
        return 0;
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
        $this->getClient()->assertConnectionEndpoints($this, $connection);
        $this->assertConnectionInvariants($connection);
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
