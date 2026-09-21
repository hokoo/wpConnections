<?php

namespace iTRON\wpConnections\Internal;

use DateTimeImmutable;
use Throwable;

/**
 * @internal Converges one logical site wake-up toward current ledger truth.
 */
final class DeletedPostRepairReconciler
{
    private DeletedPostRepairReconcilerLedgerInterface $ledger;
    private DeletedPostRepairClientRegistry $registry;
    private DeletedPostRepairSchedulerInterface $scheduler;
    private DeletedPostRepairPolicy $policy;

    public function __construct(
        DeletedPostRepairReconcilerLedgerInterface $ledger,
        DeletedPostRepairClientRegistry $registry,
        DeletedPostRepairSchedulerInterface $scheduler,
        DeletedPostRepairPolicy $policy
    ) {
        $this->ledger = $ledger;
        $this->registry = $registry;
        $this->scheduler = $scheduler;
        $this->policy = $policy;
    }

    public function reconcile(): DeletedPostRepairReconciliationResult
    {
        $now = $this->policy->utcNow();
        $automatic = $this->ledger->findNextAutomaticWakeupForClients(
            $this->registry->getAutomaticallyEligibleClientNames(),
            $now
        );
        $oldestResolvedAt = $this->ledger->findOldestResolvedAt();
        $retentionAt = null === $oldestResolvedAt
            ? null
            : $this->policy->resolvedPurgeAt($oldestResolvedAt);
        $desiredAt = $this->earliest(
            null === $automatic ? null : $automatic->getAt(),
            $retentionAt
        );

        try {
            $dispatchAvailable = $this->scheduler->isAutomaticDispatchAvailable();
            $existingAt = $this->scheduler->nextWakeupAt();
        } catch (Throwable $failure) {
            return $this->failureResult(
                $failure,
                $automatic,
                $now,
                $desiredAt,
                null,
                false
            );
        }

        if (null === $desiredAt) {
            if (null === $existingAt) {
                return new DeletedPostRepairReconciliationResult(
                    DeletedPostRepairReconciliationResult::NO_WORK,
                    null,
                    null,
                    $dispatchAvailable
                );
            }

            try {
                $this->scheduler->unscheduleWakeup($existingAt);
            } catch (Throwable $failure) {
                return $this->failureResult(
                    $failure,
                    $automatic,
                    $now,
                    null,
                    $existingAt,
                    $dispatchAvailable
                );
            }

            return new DeletedPostRepairReconciliationResult(
                DeletedPostRepairReconciliationResult::UNSCHEDULED,
                null,
                null,
                $dispatchAvailable
            );
        }

        if (null !== $existingAt && $existingAt <= $desiredAt) {
            return new DeletedPostRepairReconciliationResult(
                DeletedPostRepairReconciliationResult::KEPT,
                $desiredAt,
                $existingAt,
                $dispatchAvailable
            );
        }

        try {
            $this->scheduler->scheduleWakeup($desiredAt);
        } catch (Throwable $failure) {
            return $this->failureResult(
                $failure,
                $automatic,
                $now,
                $desiredAt,
                $existingAt,
                $dispatchAvailable
            );
        }

        if (null !== $existingAt) {
            try {
                $this->scheduler->unscheduleWakeup($existingAt);
            } catch (Throwable $failure) {
                $diagnostic = $this->diagnostic($failure);
                $this->recordFailure($automatic, $diagnostic, $now);

                return new DeletedPostRepairReconciliationResult(
                    DeletedPostRepairReconciliationResult::SCHEDULED_WITH_DUPLICATE,
                    $desiredAt,
                    $desiredAt,
                    $dispatchAvailable,
                    $diagnostic
                );
            }
        }

        return new DeletedPostRepairReconciliationResult(
            DeletedPostRepairReconciliationResult::SCHEDULED,
            $desiredAt,
            $desiredAt,
            $dispatchAvailable
        );
    }

    private function failureResult(
        Throwable $failure,
        ?DeletedPostRepairAutomaticWakeup $automatic,
        DateTimeImmutable $now,
        ?DateTimeImmutable $desiredAt,
        ?DateTimeImmutable $scheduledAt,
        bool $dispatchAvailable
    ): DeletedPostRepairReconciliationResult {
        $diagnostic = $this->diagnostic($failure);
        $this->recordFailure($automatic, $diagnostic, $now);

        return new DeletedPostRepairReconciliationResult(
            DeletedPostRepairReconciliationResult::FAILED,
            $desiredAt,
            $scheduledAt,
            $dispatchAvailable,
            $diagnostic
        );
    }

    private function recordFailure(
        ?DeletedPostRepairAutomaticWakeup $automatic,
        DeletedPostRepairDiagnostic $diagnostic,
        DateTimeImmutable $now
    ): void {
        if (null === $automatic) {
            return;
        }

        $this->ledger->recordWakeupFailure(
            $automatic->getRepairKey(),
            $diagnostic,
            $now
        );
    }

    private function diagnostic(Throwable $failure): DeletedPostRepairDiagnostic
    {
        return new DeletedPostRepairDiagnostic(
            'scheduler',
            get_class($failure),
            (string) $failure->getCode(),
            $failure->getMessage()
        );
    }

    private function earliest(
        ?DateTimeImmutable $first,
        ?DateTimeImmutable $second
    ): ?DateTimeImmutable {
        if (null === $first) {
            return $second;
        }
        if (null === $second) {
            return $first;
        }

        return $first <= $second ? $first : $second;
    }
}
