<?php

namespace iTRON\wpConnections\Internal;

use Closure;
use InvalidArgumentException;
use iTRON\wpConnections\Client;
use iTRON\wpConnections\Exceptions\ClientRegisterFail;
use iTRON\wpHooksDispatcher\Contracts\SiteContextProvider;
use iTRON\wpHooksDispatcher\SiteContext;
use LogicException;
use Throwable;

/**
 * Current-process, site-context-aware Client registry for future repair runners.
 *
 * This primitive remains dormant until the 2.0 coordinator activates it.
 *
 * @internal
 */
final class DeletedPostRepairClientRegistry
{
    private SiteContextProvider $contexts;
    private Closure $requestReconciliation;

    /**
     * @var array<string, array{
     *     context: SiteContext,
     *     client: Client,
     *     client_id: int,
     *     client_name: string,
     *     token: int,
     *     automatic: bool,
     *     registration: DeletedPostRepairClientRegistration
     * }>
     */
    private array $owners = [];

    /** @var array<int, string> */
    private array $clientOwners = [];

    /** @var array<string, int> */
    private array $ownerReservations = [];

    private int $nextToken = 0;

    public function __construct(
        SiteContextProvider $contexts,
        ?callable $requestReconciliation = null
    ) {
        $this->contexts = $contexts;
        $this->requestReconciliation = null === $requestReconciliation
            ? static function (SiteContext $context): void {
                unset($context);
            }
            : Closure::fromCallable($requestReconciliation);
    }

    /**
     * Registers a Client only after its caller considers initialization successful.
     *
     * @throws ClientRegisterFail
     */
    public function register(
        Client $client,
        bool $automaticEnabled = true
    ): DeletedPostRepairClientRegistration {
        $context = $this->currentContext();
        $clientName = $client->getName();
        $this->assertClientName($clientName);
        $ownerKey = $this->ownerKey($context, $clientName);
        $clientId = spl_object_id($client);

        $existingOwnerKey = $this->clientOwners[ $clientId ] ?? null;
        if (null !== $existingOwnerKey) {
            $existing = $this->owners[ $existingOwnerKey ] ?? null;
            if (
                $existingOwnerKey === $ownerKey &&
                null !== $existing &&
                $existing['client'] === $client
            ) {
                return $existing['registration'];
            }

            throw new ClientRegisterFail(
                'A deleted-post repair Client object cannot be registered in another site context.'
            );
        }

        if (isset($this->owners[ $ownerKey ]) || isset($this->ownerReservations[ $ownerKey ])) {
            throw new ClientRegisterFail(
                'A deleted-post repair Client is already registered for this WordPress site context.'
            );
        }

        $this->ownerReservations[ $ownerKey ] = $clientId;
        $token = ++$this->nextToken;
        $registration = new DeletedPostRepairClientRegistration(
            $this,
            $ownerKey,
            $clientId,
            $token
        );

        try {
            $this->owners[ $ownerKey ] = [
                'context' => $context,
                'client' => $client,
                'client_id' => $clientId,
                'client_name' => $clientName,
                'token' => $token,
                'automatic' => $automaticEnabled,
                'registration' => $registration,
            ];
            $this->clientOwners[ $clientId ] = $ownerKey;

            if ($automaticEnabled) {
                $this->signalReconciliation($context);
            }

            return $registration;
        } catch (Throwable $failure) {
            unset($this->owners[ $ownerKey ], $this->clientOwners[ $clientId ]);
            throw $failure;
        } finally {
            unset($this->ownerReservations[ $ownerKey ]);
        }
    }

    public function resolve(string $clientName): ?Client
    {
        $this->assertClientName($clientName);
        $context = $this->currentContext();
        $owner = $this->owners[ $this->ownerKey($context, $clientName) ] ?? null;

        return null === $owner ? null : $owner['client'];
    }

    public function resolveAutomatically(string $clientName): ?Client
    {
        $this->assertClientName($clientName);
        $context = $this->currentContext();
        $owner = $this->owners[ $this->ownerKey($context, $clientName) ] ?? null;

        return null === $owner || ! $owner['automatic'] ? null : $owner['client'];
    }

    /**
     * @return string[] Canonical Client names in deterministic order.
     */
    public function getAutomaticallyEligibleClientNames(): array
    {
        $contextKey = $this->contextKey($this->currentContext());
        $names = [];
        foreach ($this->owners as $owner) {
            if (
                $owner['automatic'] &&
                $this->contextKey($owner['context']) === $contextKey
            ) {
                $names[] = $owner['client_name'];
            }
        }
        sort($names, SORT_STRING);

        return $names;
    }

    public function revoke(string $ownerKey, int $clientId, int $token): void
    {
        $owner = $this->owners[ $ownerKey ] ?? null;
        if (
            null === $owner ||
            $owner['client_id'] !== $clientId ||
            $owner['token'] !== $token
        ) {
            return;
        }

        unset($this->owners[ $ownerKey ], $this->clientOwners[ $clientId ]);
    }

    public function setAutomaticEnabled(
        string $ownerKey,
        int $clientId,
        int $token,
        bool $enabled
    ): bool {
        $owner = $this->owners[ $ownerKey ] ?? null;
        if (
            null === $owner ||
            $owner['client_id'] !== $clientId ||
            $owner['token'] !== $token
        ) {
            return false;
        }

        if (
            $this->contextKey($this->currentContext()) !==
            $this->contextKey($owner['context'])
        ) {
            throw new LogicException(
                'Deleted-post repair Client context changed after registration.'
            );
        }

        if ($owner['automatic'] === $enabled) {
            return false;
        }

        $this->owners[ $ownerKey ]['automatic'] = $enabled;
        if (! $enabled) {
            return true;
        }

        try {
            $this->signalReconciliation($owner['context']);
        } catch (Throwable $failure) {
            $this->owners[ $ownerKey ]['automatic'] = false;
            throw $failure;
        }

        return true;
    }

    public function isRegistrationActive(string $ownerKey, int $clientId, int $token): bool
    {
        $owner = $this->owners[ $ownerKey ] ?? null;

        return null !== $owner &&
            $owner['client_id'] === $clientId &&
            $owner['token'] === $token;
    }

    public function isAutomaticEnabled(string $ownerKey, int $clientId, int $token): bool
    {
        $owner = $this->owners[ $ownerKey ] ?? null;

        return null !== $owner &&
            $owner['client_id'] === $clientId &&
            $owner['token'] === $token &&
            $owner['automatic'];
    }

    private function signalReconciliation(SiteContext $context): void
    {
        ($this->requestReconciliation)($context);
    }

    private function currentContext(): SiteContext
    {
        $context = $this->contexts->current();
        if (
            0 >= $context->blogId() ||
            '' === $context->databasePrefix() ||
            64 < strlen($context->databasePrefix()) ||
            ! preg_match('/^[A-Za-z0-9_]+$/D', $context->databasePrefix())
        ) {
            throw new InvalidArgumentException('Deleted-post repair site context is invalid.');
        }

        return $context;
    }

    private function assertClientName(string $clientName): void
    {
        if (
            '' === $clientName ||
            191 < strlen($clientName) ||
            ! preg_match('/^[a-z0-9_-]+$/D', $clientName)
        ) {
            throw new InvalidArgumentException('Deleted-post repair Client name is not canonical.');
        }
    }

    private function ownerKey(SiteContext $context, string $clientName): string
    {
        return $this->contextKey($context) . "\0" . $clientName;
    }

    private function contextKey(SiteContext $context): string
    {
        return $context->blogId() . "\0" . $context->databasePrefix();
    }
}
