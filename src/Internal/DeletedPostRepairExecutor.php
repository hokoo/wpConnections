<?php

namespace iTRON\wpConnections\Internal;

use DateTimeImmutable;
use InvalidArgumentException;
use iTRON\wpConnections\AtomicStorageInterface;
use iTRON\wpConnections\Client;
use iTRON\wpConnections\Exceptions\StorageFailure;
use iTRON\wpConnections\TransactionContext;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * @internal Executes one already-claimed repair without owning claim selection.
 */
final class DeletedPostRepairExecutor
{
    public const MODE_AUTOMATIC = 'automatic';
    public const MODE_MANUAL = 'manual';

    private const OPERATION_DELETE_POST_CONNECTIONS = 'delete_post_connections:v1';
    private const MAX_UNATTENDED_ATTEMPTS = 9;

    private DeletedPostRepairLedgerInterface $ledger;
    private DeletedPostRepairPolicy $policy;

    public function __construct(
        DeletedPostRepairLedgerInterface $ledger,
        DeletedPostRepairPolicy $policy
    ) {
        $this->ledger = $ledger;
        $this->policy = $policy;
    }

    public function execute(
        Client $client,
        DeletedPostRepairLease $lease,
        string $mode
    ): DeletedPostRepairExecutionResult {
        $this->assertMode($mode);
        $now = $this->policy->utcNow();
        $record = $this->ledger->findForClient($client->getName(), $lease->getRepairKey());
        if (! $this->isLiveClaim($record, $client, $lease, $now)) {
            return new DeletedPostRepairExecutionResult(
                DeletedPostRepairExecutionResult::LEASE_LOST,
                false
            );
        }

        $identity = $record->getIdentity();
        if (self::OPERATION_DELETE_POST_CONNECTIONS !== $identity->getOperation()) {
            return $this->failWithoutCleanup(
                $client,
                $record,
                $lease,
                $mode,
                $now,
                new RuntimeException('Unsupported deleted-post repair operation.')
            );
        }

        $storage = $client->getStorage();
        if (! $storage instanceof AtomicStorageInterface) {
            return $this->failWithoutCleanup(
                $client,
                $record,
                $lease,
                $mode,
                $now,
                new RuntimeException('Deleted-post repair requires atomic storage.')
            );
        }

        if ($record->getStorageFingerprint() !== hash('sha256', get_class($storage))) {
            return $this->failWithoutCleanup(
                $client,
                $record,
                $lease,
                $mode,
                $now,
                new RuntimeException('Deleted-post repair storage identity changed.')
            );
        }

        if (
            self::MODE_AUTOMATIC === $mode &&
            self::MAX_UNATTENDED_ATTEMPTS < $record->getAttemptCount()
        ) {
            return $this->failWithoutCleanup(
                $client,
                $record,
                $lease,
                $mode,
                $now,
                new RuntimeException('Deleted-post automatic repair budget was exceeded.'),
                'ledger'
            );
        }

        $cleanupAttempted = false;
        try {
            $deleted = $client->runAtomically(
                function () use ($storage, $identity, &$cleanupAttempted): int {
                    $cleanupAttempted = true;

                    return $storage->deleteByObjectID($identity->getPostId());
                },
                TransactionContext::strictRoot()
            );
            if (! is_int($deleted) || 0 > $deleted) {
                throw new UnexpectedValueException(
                    'Deleted-post cleanup returned an invalid affected-row count.'
                );
            }
        } catch (Throwable $failure) {
            return $this->finalizeFailure(
                $client,
                $record,
                $lease,
                $mode,
                $now,
                $failure,
                $cleanupAttempted,
                $this->failureCategory($failure)
            );
        }

        try {
            $finalized = 0 === $record->getFailureCount()
                ? $this->ledger->deleteTransientSuccess($lease)
                : $this->ledger->markResolved($lease, $now);
        } catch (Throwable $failure) {
            $this->logFailure(
                $client,
                $lease,
                $this->diagnostic($failure, 'ledger'),
                true
            );
            throw $failure;
        }

        return new DeletedPostRepairExecutionResult(
            $finalized
                ? DeletedPostRepairExecutionResult::RESOLVED
                : DeletedPostRepairExecutionResult::LEASE_LOST,
            true
        );
    }

    private function failWithoutCleanup(
        Client $client,
        DeletedPostRepairRecord $record,
        DeletedPostRepairLease $lease,
        string $mode,
        DateTimeImmutable $now,
        Throwable $failure,
        string $category = 'adapter'
    ): DeletedPostRepairExecutionResult {
        return $this->finalizeFailure(
            $client,
            $record,
            $lease,
            $mode,
            $now,
            $failure,
            false,
            $category,
            true
        );
    }

    private function finalizeFailure(
        Client $client,
        DeletedPostRepairRecord $record,
        DeletedPostRepairLease $lease,
        string $mode,
        DateTimeImmutable $now,
        Throwable $failure,
        bool $cleanupAttempted,
        string $category,
        bool $forceAttention = false
    ): DeletedPostRepairExecutionResult {
        $diagnostic = $this->diagnostic($failure, $category);
        $attention = $forceAttention ||
            self::MODE_MANUAL === $mode ||
            self::MAX_UNATTENDED_ATTEMPTS <= $record->getAttemptCount();

        try {
            if ($attention) {
                $finalized = $this->ledger->markNeedsAttention($lease, $diagnostic, $now);
                $outcome = DeletedPostRepairExecutionResult::NEEDS_ATTENTION;
            } else {
                $nextAttemptAt = $this->policy->nextAttemptAt(
                    $now,
                    $record->getAttemptCount() - 1
                );
                if (null === $nextAttemptAt) {
                    throw new RuntimeException('Deleted-post repair retry delay is unavailable.');
                }
                $finalized = $this->ledger->markRetryWait(
                    $lease,
                    $diagnostic,
                    $nextAttemptAt,
                    $now
                );
                $outcome = DeletedPostRepairExecutionResult::RETRY_WAIT;
            }
        } catch (Throwable $transitionFailure) {
            $this->logFailure(
                $client,
                $lease,
                $this->diagnostic($transitionFailure, 'ledger'),
                $cleanupAttempted
            );
            throw $transitionFailure;
        }

        $this->logFailure($client, $lease, $diagnostic, $cleanupAttempted);

        return new DeletedPostRepairExecutionResult(
            $finalized ? $outcome : DeletedPostRepairExecutionResult::LEASE_LOST,
            $cleanupAttempted
        );
    }

    private function isLiveClaim(
        ?DeletedPostRepairRecord $record,
        Client $client,
        DeletedPostRepairLease $lease,
        DateTimeImmutable $now
    ): bool {
        if (null === $record) {
            return false;
        }

        $identity = $record->getIdentity();

        return $identity->getKey() === $lease->getRepairKey() &&
            $identity->getClientName() === $client->getName() &&
            DeletedPostRepairStatus::RUNNING === $record->getStatus() &&
            $record->getLeaseToken() === $lease->getToken() &&
            null !== $record->getLeaseExpiresAt() &&
            $record->getLeaseExpiresAt() > $now &&
            $record->getUpdatedAt() <= $now;
    }

    private function diagnostic(Throwable $failure, string $category): DeletedPostRepairDiagnostic
    {
        return new DeletedPostRepairDiagnostic(
            $category,
            get_class($failure),
            (string) $failure->getCode(),
            $failure->getMessage()
        );
    }

    private function failureCategory(Throwable $failure): string
    {
        return $failure instanceof StorageFailure ? 'storage' : 'cleanup';
    }

    private function logFailure(
        Client $client,
        DeletedPostRepairLease $lease,
        DeletedPostRepairDiagnostic $diagnostic,
        bool $cleanupAttempted
    ): void {
        try {
            $client->getLogger()->error(
                'wpConnections deleted-post cleanup failed.',
                [
                    'repair_key' => $lease->getRepairKey(),
                    'category' => $diagnostic->getCategory(),
                    'failure_class' => $diagnostic->getClass(),
                    'failure_code' => $diagnostic->getCode(),
                    'cleanup_attempted' => $cleanupAttempted,
                ]
            );
        } catch (Throwable $loggingFailure) {
            // Diagnostics must never alter the durable repair outcome.
        }
    }

    private function assertMode(string $mode): void
    {
        if (! in_array($mode, [ self::MODE_AUTOMATIC, self::MODE_MANUAL ], true)) {
            throw new InvalidArgumentException('Unknown deleted-post repair execution mode.');
        }
    }
}
