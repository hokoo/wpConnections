<?php

namespace iTRON\wpConnections;

/**
 * Optional REST capability for a client-owned non-post batch resolver.
 * Eligibility must include current-user authorization for the requested context.
 */
interface RestEntityAdapterInterface extends BatchEntityResolverInterface
{
    /** @return string[] Supported entity filter names. */
    public function getSupportedRestFilters(): array;

    /**
     * Return IDs eligible for this user, context and complete filter set.
     * The input map contains only resolved objects for one entity type.
     *
     * @param array<int, object> $entities
     * @param array<string, string[]> $filters
     * @return int[]
     */
    public function getRestEligibleIds(array $entities, array $filters, string $context): array;

    /** Return only permission-safe fields for the requested context. */
    public function prepareEntityForRest(object $entity, string $context): array;
}
