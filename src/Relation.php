<?php

namespace iTRON\wpConnections;

use iTRON\wpConnections\Exceptions\ConnectionWrongData;

class Relation extends Abstracts\Relation
{
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
        if (
            empty($connectionQuery->get('from')) ||
            empty($connectionQuery->get('to'))
        ) {
            $e = new Exceptions\MissingParameters();
            $e
                ->setParam('from')
                ->setParam('to');

            throw $e;
        }

        // Self-connection ability
        if (! $this->closurable && $connectionQuery->get('from') === $connectionQuery->get('to')) {
            throw new Exceptions\ConnectionWrongData('Closurable not allowed by relation settings.', 301);
        }

        // Cardinality check
        $cardinality = explode('-', $this->cardinality);
        $output = $cardinality[0];
        $input = $cardinality[1];

        if ('1' === $output) {
            $query = new Query\Connection($connectionQuery->get('from'));
            $query->set('relation', $this->name);

            $check_output = $this->findConnections($query);
            if (! $check_output->isEmpty()) {
                throw new Exceptions\ConnectionWrongData('Cardinality violation.', 302);
            }
        }

        if ('1' === $input) {
            $query = new Query\Connection(0, $connectionQuery->get('to'));
            $query->set('relation', $this->name);

            $check_input = $this->findConnections($query);
            if (! $check_input->isEmpty()) {
                throw new Exceptions\ConnectionWrongData('Cardinality violation.', 302);
            }
        }

        // Duplicatable check
        $query = new Query\Connection($connectionQuery->get('from'), $connectionQuery->get('to'));
        $query->set('relation', $this->name);

        if (! $this->duplicatable) {
            $check_duplicatable = $this->findConnections($query);
            if (!$check_duplicatable->isEmpty()) {
                throw new Exceptions\ConnectionWrongData('Duplicatable violation.', 303);
            }
        }

        // Create connection
        $connectionQuery->set('relation', $this->name);

        do_action('wpConnections/relation/creating', $connectionQuery);

        $this->getClient()->getStorage()->createConnection($connectionQuery);

        return new Connection($connectionQuery);
    }

    /**
     * @throws ConnectionWrongData
     */
    public function updateConnection(Query\Connection $connectionQuery): bool
    {
        $connectionQuery->set('relation', $this->name);
        return $this->getClient()->getStorage()->updateConnection($connectionQuery);
    }

    /**
     * @throws ConnectionWrongData
     */
    public function updateConnectionMeta(Query\Connection $connectionQuery): bool
    {
        $connectionQuery->set('relation', $this->name);
        $objectID = $connectionQuery->get('id');

        /** @var Query\MetaCollection $metaQuery */
        $metaQuery = $connectionQuery->get('meta');

        if (! $metaQuery->isUpdate()) {
            // Remove all meta fields first if false === isUpdate.
            $this->getClient()->getStorage()->removeConnectionMeta($objectID, new Query\MetaCollection());
        } else {
            // Check the array items for false === $isUpdate fields in order to remove older values.
            $toRemove = $metaQuery->where('isUpdate', false);
            if (! $toRemove->isEmpty()) {
                foreach ($toRemove->getIterator() as $meta) {
                    /** @var Meta $meta */
                    $meta->setValue(null);
                }
                $this->getClient()->getStorage()->removeConnectionMeta($objectID, $toRemove);
            }
        }

        // Finally, insert new meta fields.
        if (! $metaQuery->isEmpty()) {
            $this->getClient()->getStorage()->addConnectionMeta($objectID, $metaQuery);
        }

        return true;
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

        return $this->getClient()->getStorage()->findConnections($connectionQuery);
    }

    public function hasConnectionID(int $connectionID): bool
    {
        $connectionQuery = $connectionQuery ?? new Query\Connection();
        $connectionQuery->set('id', $connectionID);
        return ! $this->findConnections($connectionQuery)->isEmpty();
    }
}
