<?php

namespace iTRON\wpConnections;

/** A source connection and its projected endpoints, keyed by physical role. */
final class ConnectionResolutionResult
{
    private Connection $connection;
    /** @var array<string, ResolvedEndpoint> */
    private array $endpoints;

    /** @param array<string, ResolvedEndpoint> $endpoints */
    public function __construct(Connection $connection, array $endpoints)
    {
        $this->connection = $connection;
        $this->endpoints = $endpoints;
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /** @return array<string, ResolvedEndpoint> */
    public function getEndpoints(): array
    {
        return $this->endpoints;
    }
}
