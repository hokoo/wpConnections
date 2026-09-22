<?php

namespace iTRON\wpConnections;

/** One projected physical side, including a generic unavailable slot. */
final class ResolvedEndpoint
{
    public const RESOLVED = 'resolved';
    public const UNAVAILABLE = 'unavailable';

    private string $role;
    private int $id;
    private ?string $entityType;
    private ?object $entity;

    public function __construct(string $role, int $id, ?string $entityType, ?object $entity)
    {
        $this->role = $role;
        $this->id = $id;
        $this->entityType = $entityType;
        $this->entity = $entity;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getEntityType(): ?string
    {
        return $this->entityType;
    }

    public function getStatus(): string
    {
        return null === $this->entity ? self::UNAVAILABLE : self::RESOLVED;
    }

    public function getEntity(): ?object
    {
        return $this->entity;
    }
}
