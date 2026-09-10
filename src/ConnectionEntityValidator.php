<?php

namespace iTRON\wpConnections;

use iTRON\wpConnections\Abstracts\Connection as AbstractConnection;
use iTRON\wpConnections\Exceptions\ClientRegisterFail;
use iTRON\wpConnections\Exceptions\ConnectionEndpointInvalid;
use iTRON\wpConnections\Exceptions\ConnectionEndpointNotFound;
use iTRON\wpConnections\Exceptions\ConnectionEndpointResolverFail;
use iTRON\wpConnections\Exceptions\ConnectionEndpointTypeMismatch;
use iTRON\wpConnections\Exceptions\ConnectionEndpointTypeUnsupported;
use Throwable;
use WP_Post;

/**
 * Client-scoped endpoint resolver registry and mutation validator.
 *
 * @internal
 */
final class ConnectionEntityValidator
{
    /** @var array<string, EntityResolverInterface> */
    private array $resolvers = [];
    private bool $locked = false;

    /**
     * @throws ClientRegisterFail
     */
    public function registerResolver(EntityResolverInterface $resolver): void
    {
        if ($this->locked) {
            throw new ClientRegisterFail(
                'Entity resolvers must be registered before the first connection mutation.'
            );
        }

        try {
            $types = $resolver->getSupportedEntityTypes();
        } catch (Throwable $exception) {
            throw new ClientRegisterFail(
                'Entity resolver types could not be read.',
                4,
                $exception
            );
        }
        if (empty($types)) {
            throw new ClientRegisterFail('Entity resolver must declare at least one entity type.');
        }

        $validatedTypes = [];
        foreach ($types as $entityType) {
            if (! is_string($entityType) || '' === $entityType) {
                throw new ClientRegisterFail('Entity resolver types must be non-empty strings.');
            }

            if (post_type_exists($entityType)) {
                throw new ClientRegisterFail(
                    "Entity resolver cannot claim WordPress post type: {$entityType}."
                );
            }

            if (isset($this->resolvers[ $entityType ]) || isset($validatedTypes[ $entityType ])) {
                throw new ClientRegisterFail("Duplicate entity resolver type: {$entityType}.");
            }

            $validatedTypes[ $entityType ] = true;
        }

        foreach (array_keys($validatedTypes) as $entityType) {
            $this->resolvers[ $entityType ] = $resolver;
        }
    }

    public function assertEndpoints(Relation $relation, AbstractConnection $connection): void
    {
        $this->locked = true;
        $this->assertEndpoint('from', $connection->from, $relation->from);
        $this->assertEndpoint('to', $connection->to, $relation->to);
    }

    private function assertEndpoint(string $side, int $entityId, string $entityType): void
    {
        if (0 >= $entityId) {
            throw new ConnectionEndpointInvalid($side, $entityId);
        }

        if (post_type_exists($entityType)) {
            if (isset($this->resolvers[ $entityType ])) {
                throw new ConnectionEndpointResolverFail($side, $entityType, $entityId);
            }

            $post = get_post($entityId);
            if (! $post instanceof WP_Post) {
                throw new ConnectionEndpointNotFound($side, $entityId);
            }

            if ($entityType !== $post->post_type) {
                throw new ConnectionEndpointTypeMismatch($side, $entityType, $post->post_type);
            }

            return;
        }

        if (! isset($this->resolvers[ $entityType ])) {
            throw new ConnectionEndpointTypeUnsupported($side, $entityType);
        }

        try {
            $resolution = $this->resolvers[ $entityType ]->resolve($entityId, $entityType);
        } catch (Throwable $exception) {
            throw new ConnectionEndpointResolverFail($side, $entityType, $entityId, $exception);
        }

        if (EntityResolution::ACCEPTED === $resolution->getStatus()) {
            return;
        }

        if (EntityResolution::MISSING === $resolution->getStatus()) {
            throw new ConnectionEndpointNotFound($side, $entityId);
        }

        if (EntityResolution::WRONG_TYPE === $resolution->getStatus()) {
            if (empty($resolution->getActualEntityType())) {
                throw new ConnectionEndpointResolverFail($side, $entityType, $entityId);
            }

            throw new ConnectionEndpointTypeMismatch(
                $side,
                $entityType,
                $resolution->getActualEntityType()
            );
        }

        throw new ConnectionEndpointResolverFail($side, $entityType, $entityId);
    }
}
