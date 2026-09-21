<?php

namespace iTRON\wpConnections\Tests;

use DateTimeImmutable;
use DateTimeZone;
use iTRON\wpConnections\Abstracts\Connection as AbstractConnection;
use iTRON\wpConnections\Abstracts\Storage;
use iTRON\wpConnections\AtomicStorageInterface;
use iTRON\wpConnections\Client;
use iTRON\wpConnections\ConnectionCollection;
use iTRON\wpConnections\Internal\DeletedPostRepairClockInterface;
use iTRON\wpConnections\Internal\DeletedPostRepairDiagnostic;
use iTRON\wpConnections\Internal\DeletedPostRepairExecutor;
use iTRON\wpConnections\Internal\DeletedPostRepairIdentity;
use iTRON\wpConnections\Internal\DeletedPostRepairLease;
use iTRON\wpConnections\Internal\DeletedPostRepairLedgerInterface;
use iTRON\wpConnections\Internal\DeletedPostRepairPolicy;
use iTRON\wpConnections\Internal\DeletedPostRepairRecord;
use iTRON\wpConnections\Internal\DeletedPostRepairStatus;
use iTRON\wpConnections\MetaCollection;
use iTRON\wpConnections\Query\Connection as ConnectionQuery;
use iTRON\wpConnections\Query\MetaCollection as MetaQueryCollection;
use iTRON\wpConnections\TransactionContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class DeletedPostRepairExecutorClock implements DeletedPostRepairClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(DateTimeImmutable $now)
    {
        $this->now = $now;
    }

    public function utcNow(): DateTimeImmutable
    {
        return $this->now;
    }

    public function monotonicSeconds(): float
    {
        return 100.0;
    }

    public function setNow(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }
}

class DeletedPostRepairExecutorStorage extends Storage
{
    /** @var mixed */
    public $deleteResult = 0;
    public ?Throwable $deleteFailure = null;
    public int $deleteCalls = 0;
    public array $deletedPostIds = [];

    public function createConnection(ConnectionQuery $connectionQuery): int
    {
        return 1;
    }

    public function updateConnection(AbstractConnection $connection): bool
    {
        return true;
    }

    public function deleteSpecificConnections($connectionIDs): int
    {
        return 0;
    }

    public function deleteByObjectID(
        $objectIDs,
        string $relation = '',
        bool $onlyFrom = false,
        bool $onlyTo = false
    ): int {
        $this->deleteCalls++;
        $this->deletedPostIds[] = $objectIDs;
        if (null !== $this->deleteFailure) {
            throw $this->deleteFailure;
        }

        return $this->deleteResult;
    }

    public function deleteDirectedConnections(
        ?int $from = null,
        ?int $to = null,
        string $relation = ''
    ): int {
        return 0;
    }

    public function findConnections(ConnectionQuery $params): ConnectionCollection
    {
        return new ConnectionCollection();
    }

    public function addConnectionMeta(int $objectID, MetaCollection $metaCollection): void
    {
    }

    public function removeConnectionMeta(int $objectID, MetaQueryCollection $metaQuery)
    {
        return 0;
    }
}

final class DeletedPostRepairExecutorAtomicStorage extends DeletedPostRepairExecutorStorage implements AtomicStorageInterface
{
    public function runAtomically(callable $operation, TransactionContext $context)
    {
        return $operation();
    }
}

final class DeletedPostRepairExecutorLogger extends AbstractLogger
{
    public array $records = [];
    public ?Throwable $failure = null;

    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [ $level, $message, $context ];
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }
}

final class DeletedPostRepairExecutorClient extends Client
{
    private string $testName;
    private Storage $testStorage;
    private LoggerInterface $testLogger;
    public array $atomicContexts = [];
    public ?Throwable $postCommitFailure = null;

    public function __construct(string $name, Storage $storage, LoggerInterface $logger)
    {
        $this->testName = $name;
        $this->testStorage = $storage;
        $this->testLogger = $logger;
    }

    public function getName(): string
    {
        return $this->testName;
    }

    public function getStorage(): Storage
    {
        return $this->testStorage;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->testLogger;
    }

    public function runAtomically(callable $operation, TransactionContext $context = null)
    {
        $this->atomicContexts[] = $context;
        $result = $operation();
        if (null !== $this->postCommitFailure) {
            throw $this->postCommitFailure;
        }

        return $result;
    }
}

final class DeletedPostRepairExecutorTest extends TestCase
{
    private const CLIENT_NAME = 'executor-client';
    private const OPERATION = 'delete_post_connections:v1';
    private const POST_ID = 417;
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private DateTimeImmutable $now;
    private DeletedPostRepairExecutorClock $clock;
    private DeletedPostRepairPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = new DateTimeImmutable('2026-09-21 10:00:00', new DateTimeZone('UTC'));
        $this->clock = new DeletedPostRepairExecutorClock($this->now);
        $this->policy = new DeletedPostRepairPolicy($this->clock);
    }

    public function test_zero_and_positive_results_confirm_transient_cleanup_inside_strict_atomic_scope(): void
    {
        foreach ([ 0, 3 ] as $deleted) {
            $storage = new DeletedPostRepairExecutorAtomicStorage();
            $storage->deleteResult = $deleted;
            $client = $this->client($storage);
            $ledger = $this->ledger($this->record($storage));

            $result = $this->executor($ledger)->execute(
                $client,
                $this->lease(),
                DeletedPostRepairExecutor::MODE_AUTOMATIC
            );

            self::assertSame('resolved', $result->getOutcome());
            self::assertTrue($result->wasCleanupAttempted());
            self::assertCount(1, $ledger->transientSuccessCalls);
            self::assertSame([ self::POST_ID ], $storage->deletedPostIds);
            $this->assertStrictRoot($client);
        }
    }

    public function test_success_after_a_recorded_failure_marks_the_record_resolved(): void
    {
        $storage = new DeletedPostRepairExecutorAtomicStorage();
        $client = $this->client($storage);
        $ledger = $this->ledger($this->record($storage, 2, 1));

        $result = $this->executor($ledger)->execute(
            $client,
            $this->lease(),
            DeletedPostRepairExecutor::MODE_MANUAL
        );

        self::assertSame('resolved', $result->getOutcome());
        self::assertTrue($result->wasCleanupAttempted());
        self::assertCount(1, $ledger->resolvedCalls);
        self::assertSame([], $ledger->transientSuccessCalls);
    }

    public function test_automatic_failure_uses_attempt_number_for_the_next_delay_and_logs_only_safe_context(): void
    {
        $storage = new DeletedPostRepairExecutorAtomicStorage();
        $storage->deleteFailure = new RuntimeException('private table and token secret', 73);
        $logger = new DeletedPostRepairExecutorLogger();
        $client = $this->client($storage, $logger);
        $ledger = $this->ledger($this->record($storage, 1, 0));

        $result = $this->executor($ledger)->execute(
            $client,
            $this->lease(),
            DeletedPostRepairExecutor::MODE_AUTOMATIC
        );

        self::assertSame('retry_wait', $result->getOutcome());
        self::assertTrue($result->wasCleanupAttempted());
        self::assertCount(1, $ledger->retryWaitCalls);
        self::assertSame(
            '2026-09-21 10:01:00',
            $ledger->retryWaitCalls[0]['next']->format('Y-m-d H:i:s')
        );
        $diagnostic = $ledger->retryWaitCalls[0]['diagnostic'];
        self::assertSame('cleanup', $diagnostic->getCategory());
        self::assertSame(RuntimeException::class, $diagnostic->getClass());
        self::assertSame('73', $diagnostic->getCode());
        self::assertSame('[diagnostic details redacted]', $diagnostic->getSummary());
        self::assertCount(1, $logger->records);
        $encodedLog = (string) json_encode($logger->records);
        self::assertStringNotContainsString('private table', $encodedLog);
        self::assertStringNotContainsString('token secret', $encodedLog);
        self::assertStringNotContainsString('exception', strtolower($encodedLog));
    }

    public function test_ninth_automatic_failure_and_any_manual_failure_require_attention(): void
    {
        foreach (
            [
                'ninth automatic' => [ DeletedPostRepairExecutor::MODE_AUTOMATIC, 9, 8 ],
                'first manual' => [ DeletedPostRepairExecutor::MODE_MANUAL, 1, 0 ],
            ] as [ $mode, $attemptCount, $failureCount ]
        ) {
            $storage = new DeletedPostRepairExecutorAtomicStorage();
            $storage->deleteResult = -1;
            $ledger = $this->ledger($this->record($storage, $attemptCount, $failureCount));

            $result = $this->executor($ledger)->execute(
                $this->client($storage),
                $this->lease(),
                $mode
            );

            self::assertSame('needs_attention', $result->getOutcome());
            self::assertTrue($result->wasCleanupAttempted());
            self::assertCount(1, $ledger->attentionCalls);
            self::assertSame([], $ledger->retryWaitCalls);
        }
    }

    public function test_unknown_operation_and_non_atomic_adapter_reach_attention_without_cleanup(): void
    {
        $atomicStorage = new DeletedPostRepairExecutorAtomicStorage();
        $unknownLedger = $this->ledger(
            $this->record($atomicStorage, 1, 0, 'delete_everything:v1')
        );
        $unknown = $this->executor($unknownLedger)->execute(
            $this->client($atomicStorage),
            $this->lease('delete_everything:v1'),
            DeletedPostRepairExecutor::MODE_AUTOMATIC
        );

        self::assertSame('needs_attention', $unknown->getOutcome());
        self::assertFalse($unknown->wasCleanupAttempted());
        self::assertSame(0, $atomicStorage->deleteCalls);
        self::assertSame('adapter', $unknownLedger->attentionCalls[0]['diagnostic']->getCategory());

        $incapableStorage = new DeletedPostRepairExecutorStorage();
        $incapableLedger = $this->ledger($this->record($incapableStorage));
        $incapable = $this->executor($incapableLedger)->execute(
            $this->client($incapableStorage),
            $this->lease(),
            DeletedPostRepairExecutor::MODE_AUTOMATIC
        );

        self::assertSame('needs_attention', $incapable->getOutcome());
        self::assertFalse($incapable->wasCleanupAttempted());
        self::assertSame(0, $incapableStorage->deleteCalls);
        self::assertSame('adapter', $incapableLedger->attentionCalls[0]['diagnostic']->getCategory());
    }

    public function test_adapter_fingerprint_change_reaches_attention_without_cleanup(): void
    {
        $storedStorage = new DeletedPostRepairExecutorAtomicStorage();
        $runtimeStorage = new class () extends DeletedPostRepairExecutorStorage implements AtomicStorageInterface {
            public function runAtomically(callable $operation, TransactionContext $context)
            {
                return $operation();
            }
        };
        $ledger = $this->ledger($this->record($storedStorage));

        $result = $this->executor($ledger)->execute(
            $this->client($runtimeStorage),
            $this->lease(),
            DeletedPostRepairExecutor::MODE_AUTOMATIC
        );

        self::assertSame('needs_attention', $result->getOutcome());
        self::assertFalse($result->wasCleanupAttempted());
        self::assertSame(0, $runtimeStorage->deleteCalls);
    }

    public function test_missing_stale_and_expired_claims_never_reach_storage(): void
    {
        $storage = new DeletedPostRepairExecutorAtomicStorage();
        $client = $this->client($storage);
        $executor = null;

        $missing = $this->ledger(null);
        $executor = $this->executor($missing);
        $missingResult = $executor->execute(
            $client,
            $this->lease(),
            DeletedPostRepairExecutor::MODE_AUTOMATIC
        );

        $stale = $this->ledger($this->record($storage, 1, 0, self::OPERATION, str_repeat('b', 64)));
        $staleResult = $this->executor($stale)->execute(
            $client,
            $this->lease(),
            DeletedPostRepairExecutor::MODE_AUTOMATIC
        );

        $expired = $this->ledger(
            $this->record($storage, 1, 0, self::OPERATION, self::TOKEN, $this->now)
        );
        $expiredResult = $this->executor($expired)->execute(
            $client,
            $this->lease(),
            DeletedPostRepairExecutor::MODE_AUTOMATIC
        );

        foreach ([ $missingResult, $staleResult, $expiredResult ] as $result) {
            self::assertSame('lease_lost', $result->getOutcome());
            self::assertFalse($result->wasCleanupAttempted());
        }
        self::assertSame(0, $storage->deleteCalls);
        self::assertSame([], $missing->allTransitionCalls());
        self::assertSame([], $stale->allTransitionCalls());
        self::assertSame([], $expired->allTransitionCalls());
    }

    public function test_malformed_cleanup_result_is_a_durable_failure(): void
    {
        $storage = new DeletedPostRepairExecutorAtomicStorage();
        $storage->deleteResult = new \stdClass();
        $ledger = $this->ledger($this->record($storage));

        $result = $this->executor($ledger)->execute(
            $this->client($storage),
            $this->lease(),
            DeletedPostRepairExecutor::MODE_AUTOMATIC
        );

        self::assertSame('retry_wait', $result->getOutcome());
        self::assertTrue($result->wasCleanupAttempted());
        self::assertCount(1, $ledger->retryWaitCalls);
        self::assertSame('cleanup', $ledger->retryWaitCalls[0]['diagnostic']->getCategory());
    }

    public function test_logger_throwable_cannot_undo_the_durable_failure_transition(): void
    {
        $storage = new DeletedPostRepairExecutorAtomicStorage();
        $storage->deleteFailure = new RuntimeException('storage secret');
        $logger = new DeletedPostRepairExecutorLogger();
        $logger->failure = new RuntimeException('logger failed');
        $ledger = $this->ledger($this->record($storage));

        $result = $this->executor($ledger)->execute(
            $this->client($storage, $logger),
            $this->lease(),
            DeletedPostRepairExecutor::MODE_AUTOMATIC
        );

        self::assertSame('retry_wait', $result->getOutcome());
        self::assertCount(1, $ledger->retryWaitCalls);
        self::assertCount(1, $logger->records);
    }

    public function test_failed_conditional_finalization_reports_lease_loss_after_cleanup(): void
    {
        $storage = new DeletedPostRepairExecutorAtomicStorage();
        $ledger = $this->ledger($this->record($storage));
        $ledger->transientSuccessResult = false;

        $result = $this->executor($ledger)->execute(
            $this->client($storage),
            $this->lease(),
            DeletedPostRepairExecutor::MODE_AUTOMATIC
        );

        self::assertSame('lease_lost', $result->getOutcome());
        self::assertTrue($result->wasCleanupAttempted());
        self::assertSame(1, $storage->deleteCalls);
    }

    public function test_ledger_transition_failure_propagates_and_leaves_the_running_record_reclaimable(): void
    {
        $storage = new DeletedPostRepairExecutorAtomicStorage();
        $storage->deleteFailure = new RuntimeException('cleanup failed');
        $ledger = $this->ledger($this->record($storage));
        $transitionFailure = new RuntimeException('ledger unavailable');
        $ledger->transitionFailure = $transitionFailure;

        try {
            $this->executor($ledger)->execute(
                $this->client($storage),
                $this->lease(),
                DeletedPostRepairExecutor::MODE_AUTOMATIC
            );
            self::fail('Ledger transition uncertainty must propagate.');
        } catch (Throwable $failure) {
            self::assertSame($transitionFailure, $failure);
        }

        self::assertSame(DeletedPostRepairStatus::RUNNING, $ledger->record->getStatus());
        self::assertSame(self::TOKEN, $ledger->record->getLeaseToken());
    }

    public function test_post_commit_failure_is_retryable_and_later_zero_result_resolves_uncertainty(): void
    {
        $storage = new DeletedPostRepairExecutorAtomicStorage();
        $client = $this->client($storage);
        $client->postCommitFailure = new RuntimeException('success observer failed');
        $ledger = $this->ledger($this->record($storage));
        $executor = $this->executor($ledger);

        $first = $executor->execute(
            $client,
            $this->lease(),
            DeletedPostRepairExecutor::MODE_AUTOMATIC
        );

        self::assertSame('retry_wait', $first->getOutcome());
        self::assertTrue($first->wasCleanupAttempted());
        self::assertCount(1, $ledger->retryWaitCalls);

        $client->postCommitFailure = null;
        $ledger->record = $this->record($storage, 2, 1);
        $second = $executor->execute(
            $client,
            $this->lease(),
            DeletedPostRepairExecutor::MODE_AUTOMATIC
        );

        self::assertSame('resolved', $second->getOutcome());
        self::assertTrue($second->wasCleanupAttempted());
        self::assertCount(1, $ledger->resolvedCalls);
        self::assertSame(2, $storage->deleteCalls);
    }

    public function test_invalid_mode_fails_before_ledger_access(): void
    {
        $storage = new DeletedPostRepairExecutorAtomicStorage();
        $ledger = $this->ledger($this->record($storage));

        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->executor($ledger)->execute($this->client($storage), $this->lease(), 'interactive');
        } finally {
            self::assertSame(0, $ledger->findCalls);
            self::assertSame(0, $storage->deleteCalls);
        }
    }

    private function executor(object $ledger): DeletedPostRepairExecutor
    {
        return new DeletedPostRepairExecutor($ledger, $this->policy);
    }

    private function client(
        Storage $storage,
        ?DeletedPostRepairExecutorLogger $logger = null
    ): DeletedPostRepairExecutorClient {
        return new DeletedPostRepairExecutorClient(
            self::CLIENT_NAME,
            $storage,
            $logger ?? new DeletedPostRepairExecutorLogger()
        );
    }

    private function lease(string $operation = self::OPERATION): DeletedPostRepairLease
    {
        return new DeletedPostRepairLease(
            $this->identity($operation)->getKey(),
            self::TOKEN
        );
    }

    private function record(
        Storage $storage,
        int $attemptCount = 1,
        int $failureCount = 0,
        string $operation = self::OPERATION,
        string $leaseToken = self::TOKEN,
        ?DateTimeImmutable $leaseExpiresAt = null
    ): DeletedPostRepairRecord {
        $failure = 0 < $failureCount
            ? new DeletedPostRepairDiagnostic('cleanup', RuntimeException::class, '73', 'redacted')
            : null;
        $failureAt = 0 < $failureCount ? $this->now->modify('-1 minute') : null;

        return new DeletedPostRepairRecord(
            $this->identity($operation),
            get_class($storage),
            hash('sha256', get_class($storage)),
            DeletedPostRepairStatus::RUNNING,
            $attemptCount,
            $failureCount,
            null,
            $leaseToken,
            $leaseExpiresAt ?? $this->now->modify('+10 minutes'),
            $failure,
            $failureAt,
            $failureAt,
            null,
            null,
            $this->now->modify('-1 hour'),
            $this->now,
            null
        );
    }

    private function identity(string $operation = self::OPERATION): DeletedPostRepairIdentity
    {
        return new DeletedPostRepairIdentity(
            1,
            'wp_',
            self::CLIENT_NAME,
            $operation,
            self::POST_ID
        );
    }

    private function ledger(?DeletedPostRepairRecord $record): object
    {
        return new class ($record) implements DeletedPostRepairLedgerInterface {
            public ?DeletedPostRepairRecord $record;
            public int $findCalls = 0;
            public array $retryWaitCalls = [];
            public array $attentionCalls = [];
            public array $resolvedCalls = [];
            public array $transientSuccessCalls = [];
            public bool $retryWaitResult = true;
            public bool $attentionResult = true;
            public bool $resolvedResult = true;
            public bool $transientSuccessResult = true;
            public ?Throwable $transitionFailure = null;

            public function __construct(?DeletedPostRepairRecord $record)
            {
                $this->record = $record;
            }

            public function findForClient(
                string $clientName,
                string $repairKey
            ): ?DeletedPostRepairRecord {
                $this->findCalls++;

                return $this->record;
            }

            public function markRetryWait(
                DeletedPostRepairLease $lease,
                DeletedPostRepairDiagnostic $failure,
                DateTimeImmutable $nextAttemptAt,
                DateTimeImmutable $now
            ): bool {
                $this->throwTransitionFailure();
                $this->retryWaitCalls[] = [
                    'lease' => $lease,
                    'diagnostic' => $failure,
                    'next' => $nextAttemptAt,
                    'now' => $now,
                ];

                return $this->retryWaitResult;
            }

            public function markNeedsAttention(
                DeletedPostRepairLease $lease,
                DeletedPostRepairDiagnostic $failure,
                DateTimeImmutable $now
            ): bool {
                $this->throwTransitionFailure();
                $this->attentionCalls[] = [
                    'lease' => $lease,
                    'diagnostic' => $failure,
                    'now' => $now,
                ];

                return $this->attentionResult;
            }

            public function markResolved(DeletedPostRepairLease $lease, DateTimeImmutable $now): bool
            {
                $this->throwTransitionFailure();
                $this->resolvedCalls[] = [ 'lease' => $lease, 'now' => $now ];

                return $this->resolvedResult;
            }

            public function deleteTransientSuccess(DeletedPostRepairLease $lease): bool
            {
                $this->throwTransitionFailure();
                $this->transientSuccessCalls[] = [ 'lease' => $lease ];

                return $this->transientSuccessResult;
            }

            public function allTransitionCalls(): array
            {
                return [
                    ...$this->retryWaitCalls,
                    ...$this->attentionCalls,
                    ...$this->resolvedCalls,
                    ...$this->transientSuccessCalls,
                ];
            }

            private function throwTransitionFailure(): void
            {
                if (null !== $this->transitionFailure) {
                    throw $this->transitionFailure;
                }
            }
        };
    }

    private function assertStrictRoot(DeletedPostRepairExecutorClient $client): void
    {
        self::assertCount(1, $client->atomicContexts);
        $context = $client->atomicContexts[0];
        self::assertInstanceOf(TransactionContext::class, $context);
        self::assertTrue($context->isRoot());
        self::assertFalse($context->isSchemaRecoveryAllowed());
    }
}
