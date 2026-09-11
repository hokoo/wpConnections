<?php

namespace iTRON\wpConnections\Internal;

use iTRON\wpConnections\ClientRestApi;

/**
 * Immutable identity of the four built-in routes owned by one REST delegate.
 *
 * @internal
 */
final class RestRouteIdentity
{
    private string $namespace;
    private string $base;
    private string $clientName;

    public function __construct(string $namespace, string $base, string $clientName)
    {
        $this->namespace = $namespace;
        $this->base = $base;
        $this->clientName = $clientName;
    }

    public static function fromDelegate(ClientRestApi $delegate): self
    {
        return new self(
            $delegate->namespace,
            $delegate->base,
            $delegate->getClient()->getName()
        );
    }

    public function getNamespace(): string
    {
        return $this->namespace;
    }

    public function getBase(): string
    {
        return $this->base;
    }

    public function getClientName(): string
    {
        return $this->clientName;
    }

    public function getClientRoute(): string
    {
        return '/' . $this->base . '/' . $this->clientName;
    }

    public function getRelationRoute(): string
    {
        return $this->getClientRoute() . '/relation/' . '(?P<relation>[\w-]+)';
    }

    public function getConnectionRoute(): string
    {
        return $this->getRelationRoute() . '/(?P<connectionID>[\d]+)';
    }

    public function getMetaRoute(): string
    {
        return $this->getConnectionRoute() . '/meta';
    }

    public function getKey(): string
    {
        return $this->namespace . "\0" . $this->base . "\0" . $this->clientName;
    }

    public function equals(self $other): bool
    {
        return $this->getKey() === $other->getKey();
    }
}
