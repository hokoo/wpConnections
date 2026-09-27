<?php

namespace iTRON\wpConnections;

/**
 * Optional read capability for a registered non-post entity type.
 *
 * A companion is registered on the same Client as its mutation resolver. The
 * returned map contains an object for each available ID; omitted IDs and
 * invalid values are represented as unavailable by the batch projector.
 */
interface BatchEntityResolverInterface
{
    /**
     * @param int[] $entityIds Unique, positive IDs for one exact entity type.
     * @return array<int, object> Available entities keyed by requested ID.
     */
    public function resolveMany(array $entityIds, string $entityType): array;
}
