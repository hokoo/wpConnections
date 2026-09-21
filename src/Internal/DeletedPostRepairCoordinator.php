<?php

namespace iTRON\wpConnections\Internal;

use iTRON\wpConnections\Client;
use iTRON\wpHooksDispatcher\Contracts\SiteContextProvider;
use RuntimeException;
use Throwable;

/**
 * @internal Coordinates durable recovery around one Client's deleted-post cleanup.
 */
final class DeletedPostRepairCoordinator
{
    private const OPERATION = 'delete_post_connections:v1';

    private Client $client;
    private DeletedPostRepairCoordinatorLedgerInterface $ledger;
    private DeletedPostRepairExecutionInterface $executor;
    private DeletedPostRepairPolicy $policy;
    private SiteContextProvider $contexts;
    private DeletedPostRepairReconciliationInterface $reconciler;

    public function __construct(
        Client $client,
        DeletedPostRepairCoordinatorLedgerInterface $ledger,
        DeletedPostRepairExecutionInterface $executor,
        DeletedPostRepairPolicy $policy,
        SiteContextProvider $contexts,
        DeletedPostRepairReconciliationInterface $reconciler
    ) {
        $this->client = $client;
        $this->ledger = $ledger;
        $this->executor = $executor;
        $this->policy = $policy;
        $this->contexts = $contexts;
        $this->reconciler = $reconciler;
    }

    public function handle(int $postId): void
    {
        $context = $this->contexts->current();
        $this->client->assertDeletedPostRepairContext(
            $context->blogId(),
            $context->databasePrefix()
        );

        $identity = new DeletedPostRepairIdentity(
            $context->blogId(),
            $context->databasePrefix(),
            $this->client->getName(),
            self::OPERATION,
            $postId
        );
        $now = $this->policy->utcNow();
        $claim = $this->ledger->armAndTryClaim(
            $identity,
            $this->client->getStorage(),
            $now,
            $this->policy->leaseExpiresAt($now)
        );
        $lease = $claim->getLease();

        if (null === $lease) {
            if ('not_found' === $claim->getOutcome()) {
                throw new RuntimeException(
                    'The durable deleted-post repair disappeared after it was armed.'
                );
            }

            $this->reconciler->reconcile();
            return;
        }

        try {
            $this->reconciler->reconcile();
        } catch (Throwable $ignored) {
            // A durable claim exists; the final reconciliation is authoritative.
        }

        $primary = null;
        try {
            $this->executor->execute(
                $this->client,
                $lease,
                DeletedPostRepairExecutor::MODE_AUTOMATIC
            );
        } catch (Throwable $failure) {
            $primary = $failure;
        }

        try {
            $this->reconciler->reconcile();
        } catch (Throwable $failure) {
            if (null === $primary) {
                throw $failure;
            }
        }

        if (null !== $primary) {
            throw $primary;
        }
    }
}
