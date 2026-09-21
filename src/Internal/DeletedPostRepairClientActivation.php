<?php

namespace iTRON\wpConnections\Internal;

use Closure;
use iTRON\wpConnections\Client;
use iTRON\wpHooksDispatcher\ActionDispatcher;
use iTRON\wpHooksDispatcher\ActionSubscription;
use Throwable;

/**
 * @internal Owns one Client's revocable manager subscription and registry handle.
 */
final class DeletedPostRepairClientActivation
{
    private const HOOK = 'deleted_post';
    private const PRIORITY = 10;
    private const ACCEPTED_ARGUMENTS = 1;

    private Client $client;
    private DeletedPostRepairClientRegistration $registration;
    private ActionDispatcher $dispatcher;
    private DeletedPostRepairCoordinator $coordinator;
    private Closure $prepare;
    private ?ActionSubscription $subscription = null;
    private bool $active = true;

    public function __construct(
        Client $client,
        DeletedPostRepairClientRegistration $registration,
        ActionDispatcher $dispatcher,
        DeletedPostRepairCoordinator $coordinator,
        callable $prepare
    ) {
        $this->client = $client;
        $this->registration = $registration;
        $this->dispatcher = $dispatcher;
        $this->coordinator = $coordinator;
        $this->prepare = Closure::fromCallable($prepare);
    }

    public function enable(): void
    {
        if (! $this->active || null !== $this->subscription) {
            return;
        }

        ($this->prepare)();
        $subscription = $this->dispatcher->subscribe(
            self::HOOK,
            [ $this->coordinator, 'handle' ],
            self::PRIORITY,
            self::ACCEPTED_ARGUMENTS
        );

        try {
            $this->registration->enableAutomatic();
        } catch (Throwable $failure) {
            $subscription->unsubscribe();
            throw $failure;
        }

        $this->subscription = $subscription;
    }

    public function disable(): void
    {
        if (! $this->active) {
            return;
        }

        $this->registration->disableAutomatic();
        if (null !== $this->subscription) {
            $this->subscription->unsubscribe();
            $this->subscription = null;
        }
    }

    public function revoke(): void
    {
        if (! $this->active) {
            return;
        }

        if (null !== $this->subscription) {
            $this->subscription->unsubscribe();
            $this->subscription = null;
        }
        $this->registration->revoke();
        $this->active = false;
    }

    public function owns(Client $client): bool
    {
        return $this->client === $client;
    }
}
