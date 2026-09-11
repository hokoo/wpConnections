<?php

namespace iTRON\wpConnections\Internal;

/**
 * Revocable, ABA-safe ownership handle for one REST Client mapping.
 *
 * @internal
 */
final class RestRouteRegistration
{
    private bool $active = true;

    public function __construct(
        private RestRouteRegistry $registry,
        private string $ownerKey,
        private string $contextKey,
        private int $delegateId,
        private int $token
    ) {
    }

    public function revoke(): void
    {
        if (! $this->active) {
            return;
        }

        $this->registry->revoke(
            $this->ownerKey,
            $this->contextKey,
            $this->delegateId,
            $this->token
        );
        $this->active = false;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getToken(): int
    {
        return $this->token;
    }
}
