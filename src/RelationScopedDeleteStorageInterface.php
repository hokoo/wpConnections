<?php

namespace iTRON\wpConnections;

interface RelationScopedDeleteStorageInterface
{
    /**
     * Lock one connection only when it still belongs to the receiving relation.
     *
     * The method is called inside a Client-owned atomic scope immediately before
     * the legacy client-wide ID delete primitive.
     */
    public function lockConnectionForDelete(int $connectionID, string $relation): bool;
}
