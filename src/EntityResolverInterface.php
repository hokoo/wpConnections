<?php

namespace iTRON\wpConnections;

interface EntityResolverInterface
{
    /**
     * @return string[] Exact non-WordPress-post entity types owned by this resolver.
     */
    public function getSupportedEntityTypes(): array;

    public function resolve(int $entityId, string $entityType): EntityResolution;
}
