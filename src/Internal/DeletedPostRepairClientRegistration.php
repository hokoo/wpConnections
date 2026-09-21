<?php

namespace iTRON\wpConnections\Internal;

/**
 * Revocable, ABA-safe ownership handle for one repair Client mapping.
 *
 * @internal
 */
final class DeletedPostRepairClientRegistration
{
    private bool $active = true;

    public function __construct(
        private DeletedPostRepairClientRegistry $registry,
        private string $ownerKey,
        private int $clientId,
        private int $token
    ) {
    }

    public function revoke(): void
    {
        if (! $this->active) {
            return;
        }

        $this->registry->revoke($this->ownerKey, $this->clientId, $this->token);
        $this->active = false;
    }

    /**
     * Returns true only when automatic eligibility changed from disabled to enabled.
     */
    public function enableAutomatic(): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        return $this->registry->setAutomaticEnabled(
            $this->ownerKey,
            $this->clientId,
            $this->token,
            true
        );
    }

    /**
     * Returns true only when automatic eligibility changed from enabled to disabled.
     */
    public function disableAutomatic(): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        return $this->registry->setAutomaticEnabled(
            $this->ownerKey,
            $this->clientId,
            $this->token,
            false
        );
    }

    public function isAutomaticEnabled(): bool
    {
        return $this->isActive() && $this->registry->isAutomaticEnabled(
            $this->ownerKey,
            $this->clientId,
            $this->token
        );
    }

    public function isActive(): bool
    {
        return $this->active && $this->registry->isRegistrationActive(
            $this->ownerKey,
            $this->clientId,
            $this->token
        );
    }

    public function getToken(): int
    {
        return $this->token;
    }
}
