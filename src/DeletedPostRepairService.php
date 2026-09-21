<?php

namespace iTRON\wpConnections;

use InvalidArgumentException;
use iTRON\wpConnections\Exceptions\DeletedPostRepairUnavailable;
use iTRON\wpConnections\Internal\DeletedPostRepairClientRegistry;
use iTRON\wpConnections\Internal\DeletedPostRepairExecutionResult;
use iTRON\wpConnections\Internal\DeletedPostRepairExecutor;
use iTRON\wpConnections\Internal\DeletedPostRepairLedger;
use iTRON\wpConnections\Internal\DeletedPostRepairPolicy;
use iTRON\wpConnections\Internal\DeletedPostRepairRecord;
use iTRON\wpConnections\Internal\DeletedPostRepairStatus;
use iTRON\wpConnections\Internal\DeletedPostRepairWorker;
use iTRON\wpConnections\Internal\DeletedPostRepairWorkerItemResult;
use iTRON\wpConnections\Internal\SystemDeletedPostRepairClock;
use iTRON\wpHooksDispatcher\WordPressSiteContextProvider;
use Throwable;

final class DeletedPostRepairService
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;
    private const DEFAULT_TIME_BUDGET_SECONDS = 10;
    private const MAX_TIME_BUDGET_SECONDS = 60;

    private Client $client;

    /**
     * @internal Consumers obtain this service through Client.
     */
    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function getRepair(string $repairKey): ?DeletedPostRepairView
    {
        $this->assertRepairKey($repairKey);

        return $this->guard(function () use ($repairKey): ?DeletedPostRepairView {
            $ledger = $this->readyLedger();

            return $this->view($ledger->findForClient($this->client->getName(), $repairKey));
        });
    }

    public function listRepairs(
        ?string $status = null,
        ?string $afterKey = null,
        int $limit = self::DEFAULT_LIMIT
    ): DeletedPostRepairPage {
        if (null !== $status) {
            DeletedPostRepairStatus::assertValid($status);
        }
        if (null !== $afterKey) {
            $this->assertRepairKey($afterKey);
        }
        $this->assertLimit($limit);

        return $this->guard(function () use ($status, $afterKey, $limit): DeletedPostRepairPage {
            $records = $this->readyLedger()->listForClient(
                $this->client->getName(),
                $status,
                $afterKey,
                $limit + 1
            );
            $hasMore = count($records) > $limit;
            if ($hasMore) {
                array_pop($records);
            }
            $views = array_map(
                fn(DeletedPostRepairRecord $record): DeletedPostRepairView => $this->view($record),
                $records
            );
            $nextAfterKey = $hasMore && [] !== $records
                ? $records[ array_key_last($records) ]->getIdentity()->getKey()
                : null;

            return DeletedPostRepairPage::createInternal($views, $nextAfterKey);
        });
    }

    public function retryRepair(string $repairKey): DeletedPostRepairRetryResult
    {
        $this->assertRepairKey($repairKey);

        return $this->guard(function () use ($repairKey): DeletedPostRepairRetryResult {
            $ledger = $this->readyLedger();
            $record = $ledger->findForClient($this->client->getName(), $repairKey);
            if (null === $record) {
                return DeletedPostRepairRetryResult::createInternal(
                    $repairKey,
                    'not_found_or_foreign',
                    false,
                    null
                );
            }
            if (DeletedPostRepairStatus::RESOLVED === $record->getStatus()) {
                return DeletedPostRepairRetryResult::createInternal(
                    $repairKey,
                    'resolved',
                    false,
                    $this->view($record)
                );
            }

            $policy = new DeletedPostRepairPolicy(new SystemDeletedPostRepairClock());
            $now = $policy->utcNow();
            $claim = $ledger->tryClaimManually(
                $repairKey,
                $this->client->getStorage(),
                $now,
                $policy->leaseExpiresAt($now)
            );
            $lease = $claim->getLease();
            if (null === $lease) {
                return $this->claimResult($ledger, $repairKey, $claim->getOutcome());
            }

            $execution = ( new DeletedPostRepairExecutor($ledger, $policy) )->execute(
                $this->client,
                $lease,
                DeletedPostRepairExecutor::MODE_MANUAL
            );

            return DeletedPostRepairRetryResult::createInternal(
                $repairKey,
                DeletedPostRepairExecutionResult::RESOLVED === $execution->getOutcome()
                    ? 'resolved'
                    : 'retry_failed',
                $execution->wasCleanupAttempted(),
                $this->view($ledger->findForClient($this->client->getName(), $repairKey))
            );
        });
    }

    public function retryDueRepairs(
        int $limit = self::DEFAULT_LIMIT,
        int $timeBudgetSeconds = self::DEFAULT_TIME_BUDGET_SECONDS
    ): DeletedPostRepairBatchResult {
        $this->assertLimit($limit);
        if (0 >= $timeBudgetSeconds || self::MAX_TIME_BUDGET_SECONDS < $timeBudgetSeconds) {
            throw new InvalidArgumentException('Repair time budget must be between 1 and 60 seconds.');
        }

        return $this->guard(function () use ($limit, $timeBudgetSeconds): DeletedPostRepairBatchResult {
            $ledger = $this->readyLedger();
            $policy = new DeletedPostRepairPolicy(new SystemDeletedPostRepairClock());
            $worker = new DeletedPostRepairWorker(
                $ledger,
                new DeletedPostRepairClientRegistry(new WordPressSiteContextProvider()),
                new DeletedPostRepairExecutor($ledger, $policy),
                $policy
            );
            $workerResult = $worker->runForClient($this->client, $limit, $timeBudgetSeconds);
            $results = array_map(
                fn(DeletedPostRepairWorkerItemResult $item): DeletedPostRepairRetryResult =>
                    $this->workerItemResult($ledger, $item),
                $workerResult->getItems()
            );

            return DeletedPostRepairBatchResult::createInternal(
                $results,
                $workerResult->hasMoreDue(),
                $workerResult->getStopReason()
            );
        });
    }

    private function claimResult(
        DeletedPostRepairLedger $ledger,
        string $repairKey,
        string $claimOutcome
    ): DeletedPostRepairRetryResult {
        if ('not_found' === $claimOutcome) {
            $outcome = 'not_found_or_foreign';
        } elseif ('resolved' === $claimOutcome) {
            $outcome = 'resolved';
        } elseif ('already_running' === $claimOutcome) {
            $outcome = 'already_running';
        } else {
            $outcome = 'retry_failed';
        }

        return DeletedPostRepairRetryResult::createInternal(
            $repairKey,
            $outcome,
            false,
            'not_found_or_foreign' === $outcome
                ? null
                : $this->view($ledger->findForClient($this->client->getName(), $repairKey))
        );
    }

    private function workerItemResult(
        DeletedPostRepairLedger $ledger,
        DeletedPostRepairWorkerItemResult $item
    ): DeletedPostRepairRetryResult {
        $execution = $item->getExecutionResult();
        if (null !== $execution) {
            $outcome = DeletedPostRepairExecutionResult::RESOLVED === $execution->getOutcome()
                ? 'resolved'
                : 'retry_failed';
            $cleanupAttempted = $execution->wasCleanupAttempted();
        } elseif ('not_found' === $item->getClaimOutcome()) {
            $outcome = 'not_found_or_foreign';
            $cleanupAttempted = false;
        } elseif ('resolved' === $item->getClaimOutcome()) {
            $outcome = 'resolved';
            $cleanupAttempted = false;
        } elseif ('already_running' === $item->getClaimOutcome()) {
            $outcome = 'already_running';
            $cleanupAttempted = false;
        } else {
            $outcome = 'retry_failed';
            $cleanupAttempted = false;
        }

        return DeletedPostRepairRetryResult::createInternal(
            $item->getRepairKey(),
            $outcome,
            $cleanupAttempted,
            'not_found_or_foreign' === $outcome
                ? null
                : $this->view(
                    $ledger->findForClient($this->client->getName(), $item->getRepairKey())
                )
        );
    }

    private function view(?DeletedPostRepairRecord $record): ?DeletedPostRepairView
    {
        if (null === $record) {
            return null;
        }

        $failure = $record->getFailureDiagnostic();
        $wakeup = $record->getWakeupDiagnostic();

        return DeletedPostRepairView::createInternal(
            $record->getIdentity()->getKey(),
            $record->getIdentity()->getPostId(),
            $record->getStatus(),
            $record->getAttemptCount(),
            $record->getFailureCount(),
            $record->getNextAttemptAt(),
            null === $failure ? null : $failure->getCategory(),
            null === $failure ? null : $failure->getClass(),
            null === $failure ? null : $failure->getCode(),
            null === $failure ? null : $failure->getSummary(),
            $record->getFirstFailureAt(),
            $record->getLastFailureAt(),
            null === $wakeup ? null : $wakeup->getCategory(),
            null === $wakeup ? null : $wakeup->getSummary(),
            $record->getWakeupFailureAt(),
            $record->getCreatedAt(),
            $record->getUpdatedAt(),
            $record->getResolvedAt()
        );
    }

    private function readyLedger(): DeletedPostRepairLedger
    {
        $ledger = new DeletedPostRepairLedger();
        $ledger->assertReady();

        return $ledger;
    }

    /**
     * @return mixed
     */
    private function guard(callable $operation)
    {
        try {
            $this->assertCurrentContext();

            return $operation();
        } catch (DeletedPostRepairUnavailable $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new DeletedPostRepairUnavailable();
        }
    }

    private function assertCurrentContext(): void
    {
        $this->client->assertDeletedPostRepairCurrentContext();
    }

    private function assertRepairKey(string $repairKey): void
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $repairKey)) {
            throw new InvalidArgumentException('Repair key must be a lowercase SHA-256 value.');
        }
    }

    private function assertLimit(int $limit): void
    {
        if (0 >= $limit || self::MAX_LIMIT < $limit) {
            throw new InvalidArgumentException('Repair limit must be between 1 and 100.');
        }
    }
}
