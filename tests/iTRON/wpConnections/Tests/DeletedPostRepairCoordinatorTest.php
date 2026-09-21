<?php

namespace iTRON\wpConnections\Tests;

use DateTimeImmutable;
use DateTimeZone;
use iTRON\wpConnections\Abstracts\Connection as AbstractConnection;
use iTRON\wpConnections\Abstracts\Storage;
use iTRON\wpConnections\Client;
use iTRON\wpConnections\ConnectionCollection;
use iTRON\wpConnections\Internal\DeletedPostRepairClaimResult;
use iTRON\wpConnections\Internal\DeletedPostRepairClockInterface;
use iTRON\wpConnections\Internal\DeletedPostRepairCoordinator;
use iTRON\wpConnections\Internal\DeletedPostRepairCoordinatorLedgerInterface;
use iTRON\wpConnections\Internal\DeletedPostRepairDiagnostic;
use iTRON\wpConnections\Internal\DeletedPostRepairExecutionInterface;
use iTRON\wpConnections\Internal\DeletedPostRepairExecutionResult;
use iTRON\wpConnections\Internal\DeletedPostRepairIdentity;
use iTRON\wpConnections\Internal\DeletedPostRepairLease;
use iTRON\wpConnections\Internal\DeletedPostRepairPolicy;
use iTRON\wpConnections\Internal\DeletedPostRepairReconciliationInterface;
use iTRON\wpConnections\Internal\DeletedPostRepairReconciliationResult;
use iTRON\wpConnections\MetaCollection;
use iTRON\wpConnections\Query\Connection as ConnectionQuery;
use iTRON\wpConnections\Query\MetaCollection as MetaQueryCollection;
use iTRON\wpHooksDispatcher\Contracts\SiteContextProvider;
use iTRON\wpHooksDispatcher\SiteContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

final class DeletedPostRepairCoordinatorClock implements DeletedPostRepairClockInterface
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function utcNow(): DateTimeImmutable
    {
        return $this->now;
    }

    public function monotonicSeconds(): float
    {
        return 100.0;
    }
}

final class DeletedPostRepairCoordinatorContextProvider implements SiteContextProvider
{
    public function __construct(public SiteContext $context)
    {
    }

    public function current(): SiteContext
    {
        return $this->context;
    }
}

final class DeletedPostRepairCoordinatorStorage extends Storage
{
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
        return 0;
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

final class DeletedPostRepairCoordinatorClient extends Client
{
    private Storage $testStorage;

    public function __construct(private string $testName, int $siteId = 1, string $sitePrefix = 'wp_')
    {
        $this->testStorage = new DeletedPostRepairCoordinatorStorage();
        DeletedPostRepairTestClientContext::initialize($this, $siteId, $sitePrefix);
    }

    public function getName(): string
    {
        return $this->testName;
    }

    public function getStorage(): Storage
    {
        return $this->testStorage;
    }

    public function getLogger(): NullLogger
    {
        return new NullLogger();
    }
}

final class DeletedPostRepairCoordinatorLedgerDouble implements DeletedPostRepairCoordinatorLedgerInterface
{
    public array $events = [];
    public array $calls = [];
    public ?Throwable $failure = null;
    public DeletedPostRepairClaimResult $result;

    public function __construct()
    {
        $this->result = new DeletedPostRepairClaimResult(
            'acquired',
            new DeletedPostRepairLease(
                str_repeat('a', 64),
                str_repeat('b', 64)
            )
        );
    }

    public function armAndTryClaim(
        DeletedPostRepairIdentity $identity,
        object $storage,
        DateTimeImmutable $now,
        DateTimeImmutable $leaseUntil
    ): DeletedPostRepairClaimResult {
        $this->events[] = 'arm';
        $this->calls[] = [ $identity, $storage, $now, $leaseUntil ];
        if (null !== $this->failure) {
            throw $this->failure;
        }

        if (null !== $this->result->getLease()) {
            $this->result = new DeletedPostRepairClaimResult(
                'acquired',
                new DeletedPostRepairLease(
                    $identity->getKey(),
                    $this->result->getLease()->getToken()
                )
            );
        }

        return $this->result;
    }
}

final class DeletedPostRepairCoordinatorExecutorDouble implements DeletedPostRepairExecutionInterface
{
    public array $events = [];
    public array $calls = [];
    public ?Throwable $failure = null;
    public DeletedPostRepairExecutionResult $result;

    public function __construct()
    {
        $this->result = new DeletedPostRepairExecutionResult(
            DeletedPostRepairExecutionResult::RESOLVED,
            true
        );
    }

    public function execute(
        Client $client,
        DeletedPostRepairLease $lease,
        string $mode
    ): DeletedPostRepairExecutionResult {
        $this->events[] = 'execute';
        $this->calls[] = [ $client, $lease, $mode ];
        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->result;
    }
}

final class DeletedPostRepairCoordinatorReconcilerDouble implements DeletedPostRepairReconciliationInterface
{
    public array $events = [];
    public int $calls = 0;
    public array $failuresByCall = [];
    public DeletedPostRepairReconciliationResult $result;

    public function __construct()
    {
        $this->result = new DeletedPostRepairReconciliationResult(
            DeletedPostRepairReconciliationResult::NO_WORK,
            null,
            null,
            true
        );
    }

    public function reconcile(): DeletedPostRepairReconciliationResult
    {
        $this->calls++;
        $this->events[] = 'reconcile';
        if (isset($this->failuresByCall[ $this->calls ])) {
            throw $this->failuresByCall[ $this->calls ];
        }

        return $this->result;
    }
}

final class DeletedPostRepairCoordinatorTest extends TestCase
{
    private const CLIENT_NAME = 'coordinator-client';
    private const POST_ID = 417;
    private const OPERATION = 'delete_post_connections:v1';

    private DateTimeImmutable $now;
    private DeletedPostRepairCoordinatorLedgerDouble $ledger;
    private DeletedPostRepairCoordinatorExecutorDouble $executor;
    private DeletedPostRepairCoordinatorReconcilerDouble $reconciler;
    private DeletedPostRepairCoordinatorContextProvider $contexts;
    private DeletedPostRepairPolicy $policy;
    private DeletedPostRepairCoordinatorClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = new DateTimeImmutable('2026-09-21 10:00:00', new DateTimeZone('UTC'));
        $this->ledger = new DeletedPostRepairCoordinatorLedgerDouble();
        $this->executor = new DeletedPostRepairCoordinatorExecutorDouble();
        $this->reconciler = new DeletedPostRepairCoordinatorReconcilerDouble();
        $this->contexts = new DeletedPostRepairCoordinatorContextProvider(
            new SiteContext(1, 'wp_')
        );
        $this->policy = new DeletedPostRepairPolicy(
            new DeletedPostRepairCoordinatorClock($this->now)
        );
        $this->client = new DeletedPostRepairCoordinatorClient(self::CLIENT_NAME);
    }

    public function test_arm_reconcile_execute_and_final_reconcile_are_ordered(): void
    {
        $events = [];
        $this->ledger->events =& $events;
        $this->executor->events =& $events;
        $this->reconciler->events =& $events;

        $this->coordinator()->handle(self::POST_ID);

        self::assertSame([ 'arm', 'reconcile', 'execute', 'reconcile' ], $events);
        self::assertCount(1, $this->ledger->calls);
        [ $identity, $storage, $now, $leaseUntil ] = $this->ledger->calls[0];
        self::assertSame(1, $identity->getSiteId());
        self::assertSame('wp_', $identity->getSitePrefix());
        self::assertSame(self::CLIENT_NAME, $identity->getClientName());
        self::assertSame(self::OPERATION, $identity->getOperation());
        self::assertSame(self::POST_ID, $identity->getPostId());
        self::assertSame($this->client->getStorage(), $storage);
        self::assertSame($this->now, $now);
        self::assertSame('2026-09-21 10:10:00', $leaseUntil->format('Y-m-d H:i:s'));
        self::assertSame(
            hash(
                'sha256',
                pack('N', 1) . '1' .
                pack('N', 3) . 'wp_' .
                pack('N', strlen(self::CLIENT_NAME)) . self::CLIENT_NAME .
                pack('N', strlen(self::OPERATION)) . self::OPERATION .
                pack('N', 3) . '417'
            ),
            $identity->getKey()
        );
        self::assertSame($identity->getKey(), $this->executor->calls[0][1]->getRepairKey());
        self::assertSame('automatic', $this->executor->calls[0][2]);
    }

    public function test_arm_failure_propagates_before_reconciliation_or_execution(): void
    {
        $failure = new RuntimeException('ledger arm failed');
        $this->ledger->failure = $failure;

        try {
            $this->coordinator()->handle(self::POST_ID);
            self::fail('Ledger-arm uncertainty must propagate.');
        } catch (Throwable $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame(0, $this->reconciler->calls);
        self::assertSame([], $this->executor->calls);
    }

    /**
     * @dataProvider handledExecutionOutcomeProvider
     */
    public function test_handled_execution_outcome_does_not_escape_and_still_reconciles(
        string $outcome,
        bool $cleanupAttempted
    ): void
    {
        $this->executor->result = new DeletedPostRepairExecutionResult(
            $outcome,
            $cleanupAttempted
        );

        $this->coordinator()->handle(self::POST_ID);

        self::assertCount(1, $this->executor->calls);
        self::assertSame(2, $this->reconciler->calls);
    }

    public function handledExecutionOutcomeProvider(): array
    {
        return [
            'resolved' => [ DeletedPostRepairExecutionResult::RESOLVED, true ],
            'retry wait' => [ DeletedPostRepairExecutionResult::RETRY_WAIT, true ],
            'needs attention' => [ DeletedPostRepairExecutionResult::NEEDS_ATTENTION, false ],
        ];
    }

    /**
     * @dataProvider safeUnacquiredOutcomeProvider
     */
    public function test_safe_unacquired_claim_skips_cleanup_but_reconciles_durable_work(
        string $outcome
    ): void
    {
        $this->ledger->result = new DeletedPostRepairClaimResult($outcome);

        $this->coordinator()->handle(self::POST_ID);

        self::assertSame([], $this->executor->calls);
        self::assertSame(1, $this->reconciler->calls);
    }

    public function safeUnacquiredOutcomeProvider(): array
    {
        return [
            'already running' => [ 'already_running' ],
            'not due' => [ 'not_due' ],
            'resolved' => [ 'resolved' ],
            'adapter mismatch' => [ 'adapter_mismatch' ],
            'attempts exhausted' => [ 'attempts_exhausted' ],
            'unavailable' => [ 'unavailable' ],
        ];
    }

    public function test_not_found_after_arm_is_critical_and_never_runs_cleanup(): void
    {
        $this->ledger->result = new DeletedPostRepairClaimResult('not_found');

        try {
            $this->coordinator()->handle(self::POST_ID);
            self::fail('A vanished durable arm must be critical.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('durable', $failure->getMessage());
        }

        self::assertSame([], $this->executor->calls);
        self::assertSame(0, $this->reconciler->calls);
    }

    public function test_execution_uncertainty_stays_primary_when_final_reconciliation_also_fails(): void
    {
        $primary = new RuntimeException('ledger finalization failed');
        $this->executor->failure = $primary;
        $this->reconciler->failuresByCall[2] = new RuntimeException(
            'secondary reconciliation uncertainty'
        );

        try {
            $this->coordinator()->handle(self::POST_ID);
            self::fail('Execution uncertainty must propagate.');
        } catch (Throwable $caught) {
            self::assertSame($primary, $caught);
        }

        self::assertSame(2, $this->reconciler->calls);
    }

    public function test_scheduler_failure_result_after_durable_arm_does_not_block_cleanup(): void
    {
        $this->reconciler->result = new DeletedPostRepairReconciliationResult(
            DeletedPostRepairReconciliationResult::FAILED,
            $this->now,
            null,
            false,
            new DeletedPostRepairDiagnostic(
                'scheduler',
                RuntimeException::class,
                '73',
                'private scheduler failure'
            )
        );

        $this->coordinator()->handle(self::POST_ID);

        self::assertCount(1, $this->executor->calls);
        self::assertSame(2, $this->reconciler->calls);
    }

    public function test_pre_reconciliation_uncertainty_can_recover_after_cleanup(): void
    {
        $this->reconciler->failuresByCall[1] = new RuntimeException(
            'temporary ledger read uncertainty'
        );

        $this->coordinator()->handle(self::POST_ID);

        self::assertCount(1, $this->executor->calls);
        self::assertSame(2, $this->reconciler->calls);
    }

    /**
     * @dataProvider finalUncertaintyOutcomeProvider
     */
    public function test_final_reconciliation_uncertainty_propagates_after_handled_execution(
        string $outcome
    ): void
    {
        $this->executor->result = new DeletedPostRepairExecutionResult($outcome, true);
        $failure = new RuntimeException('final ledger uncertainty');
        $this->reconciler->failuresByCall[2] = $failure;

        try {
            $this->coordinator()->handle(self::POST_ID);
            self::fail('Final reconciliation uncertainty must propagate.');
        } catch (Throwable $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertCount(1, $this->executor->calls);
        self::assertSame(2, $this->reconciler->calls);
    }

    public function finalUncertaintyOutcomeProvider(): array
    {
        return [
            'resolved' => [ DeletedPostRepairExecutionResult::RESOLVED ],
            'retry wait' => [ DeletedPostRepairExecutionResult::RETRY_WAIT ],
            'needs attention' => [ DeletedPostRepairExecutionResult::NEEDS_ATTENTION ],
        ];
    }

    /**
     * @dataProvider mismatchedContextProvider
     */
    public function test_mismatched_context_fails_before_arm_or_cleanup(
        int $siteId,
        string $sitePrefix
    ): void
    {
        $this->contexts->context = new SiteContext($siteId, $sitePrefix);

        try {
            $this->coordinator()->handle(self::POST_ID);
            self::fail('A stale Client must fail before durable work.');
        } catch (Throwable $failure) {
            self::assertInstanceOf(
                \iTRON\wpConnections\Exceptions\DeletedPostRepairUnavailable::class,
                $failure
            );
        }

        self::assertSame([], $this->ledger->calls);
        self::assertSame([], $this->executor->calls);
        self::assertSame(0, $this->reconciler->calls);
    }

    public function mismatchedContextProvider(): array
    {
        return [
            'blog and prefix' => [ 2, 'wp_2_' ],
            'prefix only' => [ 1, 'other_' ],
        ];
    }

    /**
     * @dataProvider invalidPostIdProvider
     */
    public function test_invalid_post_id_fails_before_arm(int $postId): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->coordinator()->handle($postId);
        } finally {
            self::assertSame([], $this->ledger->calls);
            self::assertSame([], $this->executor->calls);
        }
    }

    public function invalidPostIdProvider(): array
    {
        return [ 'zero' => [ 0 ], 'negative' => [ -1 ] ];
    }

    private function coordinator(): DeletedPostRepairCoordinator
    {
        return new DeletedPostRepairCoordinator(
            $this->client,
            $this->ledger,
            $this->executor,
            $this->policy,
            $this->contexts,
            $this->reconciler
        );
    }
}
