<?php

namespace iTRON\wpConnections;

/**
 * Shared internal cardinality guard for high-level connection mutations.
 *
 * @internal
 */
trait CardinalityValidation
{
    private function assertCardinality(
        Relation $relation,
        Abstracts\Connection $connection
    ): void {
        $cardinality = explode('-', $relation->cardinality);

        if ('1' === $cardinality[0] && $connection->to > 0) {
            $query = new Query\Connection(0, $connection->to);
            $this->assertNoOtherConnection($relation, $query, (int) $connection->id);
        }

        if ('1' === $cardinality[1] && $connection->from > 0) {
            $query = new Query\Connection($connection->from);
            $this->assertNoOtherConnection($relation, $query, (int) $connection->id);
        }
    }

    private function assertNoOtherConnection(
        Relation $relation,
        Query\Connection $query,
        int $excludedConnectionID
    ): void {
        foreach ($relation->findConnections($query)->getIterator() as $connection) {
            if ($excludedConnectionID > 0 && $excludedConnectionID === (int) $connection->id) {
                continue;
            }

            throw new Exceptions\ConnectionWrongData('Cardinality violation.', 302);
        }
    }
}
