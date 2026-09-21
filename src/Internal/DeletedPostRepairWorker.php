<?php

namespace iTRON\wpConnections\Internal;

use Closure;
use InvalidArgumentException;
use iTRON\wpConnections\Client;
use RuntimeException;
use Throwable;

/**
 * @internal Processes one current-site bounded repair batch without hook wiring.
 */
final class DeletedPostRepairWorker
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;
    private const DEFAULT_TIME_BUDGET_SECONDS = 10;
    private const MAX_TIME_BUDGET_SECONDS = 60;

    private DeletedPostRepairWorkerLedgerInterface $ledger;
    private DeletedPostRepairClientRegistry $registry;
    private DeletedPostRepairExecutionInterface $executor;
    private DeletedPostRepairPolicy $policy;
    private Closure $requestReconciliation;

    public function __construct(
        DeletedPostRepairWorkerLedgerInterface $ledger,
        DeletedPostRepairClientRegistry $registry,
        DeletedPostRepairExecutionInterface $executor,
        DeletedPostRepairPolicy $policy,
        ?callable $requestReconciliation = null
    ) {
        $this->ledger = $ledger;
        $this->registry = $registry;
        $this->executor = $executor;
        $this->policy = $policy;
        $this->requestReconciliation = null === $requestReconciliation
            ? static function (): void {
            }
            : Closure::fromCallable($requestReconciliation);
    }

    public function runAutomatically(
        int $limit = self::DEFAULT_LIMIT,
        int $timeBudgetSeconds = self::DEFAULT_TIME_BUDGET_SECONDS
    ): DeletedPostRepairWorkerResult {
        $this->assertBounds($limit, $timeBudgetSeconds);

        return $this->runWithReconciliation(
            fn(): DeletedPostRepairWorkerResult => $this->runBatch(
                null,
                $limit,
                $timeBudgetSeconds
            )
        );
    }

    public function runForClient(
        Client $client,
        int $limit = self::DEFAULT_LIMIT,
        int $timeBudgetSeconds = self::DEFAULT_TIME_BUDGET_SECONDS
    ): DeletedPostRepairWorkerResult {
        $this->assertBounds($limit, $timeBudgetSeconds);

        return $this->runWithReconciliation(
            fn(): DeletedPostRepairWorkerResult => $this->runBatch(
                $client,
                $limit,
                $timeBudgetSeconds
            )
        );
    }

    private function runBatch(
        ?Client $manualClient,
        int $limit,
        int $timeBudgetSeconds
    ): DeletedPostRepairWorkerResult {
        $startedAt = $this->policy->monotonicSeconds();
        $purged = $this->purgeResolved($limit);
        $items = [];
        $seen = [];
        $timeBudgetReached = false;

        while (count($items) < $limit) {
            if ($this->timeBudgetReached($startedAt, $timeBudgetSeconds)) {
                $timeBudgetReached = true;
                break;
            }

            $clientNames = $this->clientNames($manualClient);
            $remaining = $limit - count($items);
            $records = $this->ledger->findDueForClients(
                $clientNames,
                $remaining,
                $this->policy->utcNow()
            );
            if ([] === $records) {
                break;
            }

            foreach ($records as $record) {
                if ($this->timeBudgetReached($startedAt, $timeBudgetSeconds)) {
                    $timeBudgetReached = true;
                    break 2;
                }

                $repairKey = $record->getIdentity()->getKey();
                if (isset($seen[ $repairKey ])) {
                    throw new RuntimeException('Deleted-post repair worker could not make ledger progress.');
                }
                $seen[ $repairKey ] = true;

                $client = $manualClient ?? $this->registry->resolveAutomatically(
                    $record->getIdentity()->getClientName()
                );
                if (null === $client) {
                    $items[] = new DeletedPostRepairWorkerItemResult(
                        $repairKey,
                        DeletedPostRepairWorkerItemResult::CLIENT_UNAVAILABLE
                    );
                    continue;
                }

                $now = $this->policy->utcNow();
                $claim = null === $manualClient
                    ? $this->ledger->tryClaimDue(
                        $repairKey,
                        $client->getStorage(),
                        $now,
                        $this->policy->leaseExpiresAt($now)
                    )
                    : $this->ledger->tryClaimDueManually(
                        $repairKey,
                        $client->getStorage(),
                        $now,
                        $this->policy->leaseExpiresAt($now)
                    );
                $lease = $claim->getLease();
                if (null === $lease) {
                    $items[] = new DeletedPostRepairWorkerItemResult(
                        $repairKey,
                        $claim->getOutcome()
                    );
                    continue;
                }

                $execution = $this->executor->execute(
                    $client,
                    $lease,
                    null === $manualClient
                        ? DeletedPostRepairExecutor::MODE_AUTOMATIC
                        : DeletedPostRepairExecutor::MODE_MANUAL
                );
                $items[] = new DeletedPostRepairWorkerItemResult(
                    $repairKey,
                    $claim->getOutcome(),
                    $execution
                );
            }
        }

        $hasMoreDue = [] !== $this->ledger->findDueForClients(
            $this->clientNames($manualClient),
            1,
            $this->policy->utcNow()
        );
        if (! $hasMoreDue) {
            $stopReason = DeletedPostRepairWorkerResult::COMPLETE;
        } elseif ($timeBudgetReached) {
            $stopReason = DeletedPostRepairWorkerResult::TIME_BUDGET;
        } elseif (count($items) >= $limit) {
            $stopReason = DeletedPostRepairWorkerResult::BATCH_LIMIT;
        } else {
            throw new RuntimeException('Deleted-post repair worker stopped with unclassified due work.');
        }

        return new DeletedPostRepairWorkerResult($items, $hasMoreDue, $stopReason, $purged);
    }

    private function purgeResolved(int $limit): int
    {
        $oldest = $this->ledger->findOldestResolvedAt();
        if (null === $oldest) {
            return 0;
        }

        $now = $this->policy->utcNow();
        if (! $this->policy->isResolvedPurgeable($oldest, $now)) {
            return 0;
        }

        return $this->ledger->purgeResolvedBefore(
            $this->policy->resolvedRetentionCutoff($now),
            $limit
        );
    }

    /**
     * @return string[]
     */
    private function clientNames(?Client $manualClient): array
    {
        return null === $manualClient
            ? $this->registry->getAutomaticallyEligibleClientNames()
            : [ $manualClient->getName() ];
    }

    private function timeBudgetReached(float $startedAt, int $timeBudgetSeconds): bool
    {
        $current = $this->policy->monotonicSeconds();
        if ($current < $startedAt) {
            throw new RuntimeException('Deleted-post repair monotonic clock moved backwards.');
        }

        return $current - $startedAt >= $timeBudgetSeconds;
    }

    private function runWithReconciliation(callable $operation): DeletedPostRepairWorkerResult
    {
        try {
            $result = $operation();
        } catch (Throwable $failure) {
            try {
                ($this->requestReconciliation)();
            } catch (Throwable $reconciliationFailure) {
                // Preserve the primary ledger/execution uncertainty.
            }
            throw $failure;
        }

        ($this->requestReconciliation)();

        return $result;
    }

    private function assertBounds(int $limit, int $timeBudgetSeconds): void
    {
        if (0 >= $limit || self::MAX_LIMIT < $limit) {
            throw new InvalidArgumentException('Repair worker limit must be between 1 and 100.');
        }
        if (0 >= $timeBudgetSeconds || self::MAX_TIME_BUDGET_SECONDS < $timeBudgetSeconds) {
            throw new InvalidArgumentException('Repair worker time budget must be between 1 and 60 seconds.');
        }
    }
}
