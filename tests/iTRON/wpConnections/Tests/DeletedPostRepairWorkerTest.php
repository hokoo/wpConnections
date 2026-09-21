<?php

namespace iTRON\wpConnections\Tests;

use DateTimeImmutable;
use DateTimeZone;
use iTRON\wpConnections\Abstracts\Connection as AbstractConnection;
use iTRON\wpConnections\Abstracts\Storage;
use iTRON\wpConnections\Client;
use iTRON\wpConnections\ConnectionCollection;
use iTRON\wpConnections\Internal\DeletedPostRepairClaimResult;
use iTRON\wpConnections\Internal\DeletedPostRepairClientRegistry;
use iTRON\wpConnections\Internal\DeletedPostRepairClockInterface;
use iTRON\wpConnections\Internal\DeletedPostRepairExecutionInterface;
use iTRON\wpConnections\Internal\DeletedPostRepairExecutionResult;
use iTRON\wpConnections\Internal\DeletedPostRepairIdentity;
use iTRON\wpConnections\Internal\DeletedPostRepairLease;
use iTRON\wpConnections\Internal\DeletedPostRepairPolicy;
use iTRON\wpConnections\Internal\DeletedPostRepairRecord;
use iTRON\wpConnections\Internal\DeletedPostRepairStatus;
use iTRON\wpConnections\Internal\DeletedPostRepairWorker;
use iTRON\wpConnections\Internal\DeletedPostRepairWorkerLedgerInterface;
use iTRON\wpConnections\MetaCollection;
use iTRON\wpConnections\Query\Connection as ConnectionQuery;
use iTRON\wpConnections\Query\MetaCollection as MetaQueryCollection;
use iTRON\wpHooksDispatcher\Contracts\SiteContextProvider;
use iTRON\wpHooksDispatcher\SiteContext;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DeletedPostRepairWorkerClock implements DeletedPostRepairClockInterface
{
    private DateTimeImmutable $now;
    private array $monotonicReadings;
    private float $lastReading;

    public function __construct(DateTimeImmutable $now, array $monotonicReadings = [ 100.0 ])
    {
        $this->now = $now;
        $this->monotonicReadings = $monotonicReadings;
        $this->lastReading = (float) end($monotonicReadings);
    }

    public function utcNow(): DateTimeImmutable
    {
        return $this->now;
    }

    public function monotonicSeconds(): float
    {
        if ([] === $this->monotonicReadings) {
            return $this->lastReading;
        }

        $this->lastReading = (float) array_shift($this->monotonicReadings);

        return $this->lastReading;
    }
}

final class DeletedPostRepairWorkerContextProvider implements SiteContextProvider
{
    public function current(): SiteContext
    {
        return new SiteContext(1, 'wp_');
    }
}

final class DeletedPostRepairWorkerStorage extends Storage
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

final class DeletedPostRepairWorkerClient extends Client
{
    private string $testName;
    private Storage $testStorage;

    public function __construct(string $name)
    {
        $this->testName = $name;
        $this->testStorage = new DeletedPostRepairWorkerStorage();
    }

    public function getName(): string
    {
        return $this->testName;
    }

    public function getStorage(): Storage
    {
        return $this->testStorage;
    }
}

final class DeletedPostRepairWorkerLedgerDouble implements DeletedPostRepairWorkerLedgerInterface
{
    /** @var array<string, DeletedPostRepairRecord> */
    public array $due = [];
    /** @var array<string, string> */
    public array $claimOutcomes = [];
    public array $automaticClaims = [];
    public array $manualClaims = [];
    public array $eligibleQueries = [];
    public ?DateTimeImmutable $oldestResolvedAt = null;
    public int $purgeResult = 0;
    public array $purgeCalls = [];
    public ?RuntimeException $claimFailure = null;

    public function findDueForClients(
        array $clientNames,
        int $limit,
        DateTimeImmutable $now
    ): array {
        sort($clientNames, SORT_STRING);
        $this->eligibleQueries[] = $clientNames;
        $records = array_filter(
            $this->due,
            static fn(DeletedPostRepairRecord $record): bool => in_array(
                $record->getIdentity()->getClientName(),
                $clientNames,
                true
            )
        );
        ksort($records, SORT_STRING);

        return array_slice(array_values($records), 0, $limit);
    }

    public function tryClaimDue(
        string $repairKey,
        object $storage,
        DateTimeImmutable $now,
        DateTimeImmutable $leaseUntil
    ): DeletedPostRepairClaimResult {
        $this->automaticClaims[] = $repairKey;

        return $this->claim($repairKey);
    }

    public function tryClaimDueManually(
        string $repairKey,
        object $storage,
        DateTimeImmutable $now,
        DateTimeImmutable $leaseUntil
    ): DeletedPostRepairClaimResult {
        $this->manualClaims[] = $repairKey;

        return $this->claim($repairKey);
    }

    public function findOldestResolvedAt(): ?DateTimeImmutable
    {
        return $this->oldestResolvedAt;
    }

    public function purgeResolvedBefore(DateTimeImmutable $cutoff, int $limit): int
    {
        $this->purgeCalls[] = [ $cutoff, $limit ];

        return $this->purgeResult;
    }

    private function claim(string $repairKey): DeletedPostRepairClaimResult
    {
        if (null !== $this->claimFailure) {
            throw $this->claimFailure;
        }

        $outcome = $this->claimOutcomes[ $repairKey ] ?? 'acquired';
        if ('unavailable' !== $outcome) {
            unset($this->due[ $repairKey ]);
        }
        if ('acquired' !== $outcome) {
            return new DeletedPostRepairClaimResult($outcome);
        }

        return new DeletedPostRepairClaimResult(
            'acquired',
            new DeletedPostRepairLease($repairKey, hash('sha256', $repairKey))
        );
    }
}

final class DeletedPostRepairWorkerExecutorDouble implements DeletedPostRepairExecutionInterface
{
    /** @var array<string, DeletedPostRepairExecutionResult> */
    public array $results = [];
    public array $calls = [];
    public ?string $throwOn = null;

    public function execute(
        Client $client,
        DeletedPostRepairLease $lease,
        string $mode
    ): DeletedPostRepairExecutionResult {
        $this->calls[] = [ $client->getName(), $lease->getRepairKey(), $mode ];
        if ($lease->getRepairKey() === $this->throwOn) {
            throw new RuntimeException('ledger transition uncertain');
        }

        return $this->results[ $lease->getRepairKey() ] ??
            new DeletedPostRepairExecutionResult('resolved', true);
    }
}

final class DeletedPostRepairWorkerTest extends TestCase
{
    private DateTimeImmutable $now;
    private DeletedPostRepairClientRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = new DateTimeImmutable('2026-09-21 10:00:00', new DateTimeZone('UTC'));
        $this->registry = new DeletedPostRepairClientRegistry(
            new DeletedPostRepairWorkerContextProvider()
        );
    }

    public function test_automatic_batch_filters_before_limit_and_continues_after_record_failure(): void
    {
        $alpha = new DeletedPostRepairWorkerClient('alpha');
        $beta = new DeletedPostRepairWorkerClient('beta');
        $this->registry->register($alpha);
        $this->registry->register($beta, false);
        $ledger = new DeletedPostRepairWorkerLedgerDouble();
        $first = $this->record('alpha', 11);
        $second = $this->record('alpha', 12);
        $disabled = $this->record('beta', 10);
        $ledger->due = $this->recordsByKey([ $disabled, $second, $first ]);
        $executor = new DeletedPostRepairWorkerExecutorDouble();
        $executor->results[ $first->getIdentity()->getKey() ] =
            new DeletedPostRepairExecutionResult('retry_wait', true);
        $signals = 0;

        $result = $this->worker($ledger, $executor, null, $signals)->runAutomatically(20, 10);

        self::assertSame([ 'alpha' ], $ledger->eligibleQueries[0]);
        self::assertSame(
            [ $first->getIdentity()->getKey(), $second->getIdentity()->getKey() ],
            array_column($executor->calls, 1)
        );
        self::assertSame('automatic', $executor->calls[0][2]);
        self::assertSame('complete', $result->getStopReason());
        self::assertFalse($result->hasMoreDue());
        self::assertCount(2, $result->getItems());
        self::assertSame(1, $signals);
    }

    public function test_only_acquired_claims_reach_executor_and_attempt_exhaustion_is_a_result(): void
    {
        $client = new DeletedPostRepairWorkerClient('alpha');
        $this->registry->register($client);
        $ledger = new DeletedPostRepairWorkerLedgerDouble();
        $acquired = $this->record('alpha', 21);
        $contended = $this->record('alpha', 22);
        $exhausted = $this->record('alpha', 23);
        $ledger->due = $this->recordsByKey([ $acquired, $contended, $exhausted ]);
        $ledger->claimOutcomes[ $contended->getIdentity()->getKey() ] = 'already_running';
        $ledger->claimOutcomes[ $exhausted->getIdentity()->getKey() ] = 'attempts_exhausted';
        $executor = new DeletedPostRepairWorkerExecutorDouble();
        $signals = 0;

        $result = $this->worker($ledger, $executor, null, $signals)->runAutomatically();

        self::assertCount(1, $executor->calls);
        self::assertSame($acquired->getIdentity()->getKey(), $executor->calls[0][1]);
        self::assertSame(
            [ 'acquired', 'already_running', 'attempts_exhausted' ],
            array_map(
                static fn($item): string => $item->getClaimOutcome(),
                $result->getItems()
            )
        );
    }

    public function test_time_budget_stops_before_another_claim_and_reports_visible_due_work(): void
    {
        $client = new DeletedPostRepairWorkerClient('alpha');
        $this->registry->register($client);
        $ledger = new DeletedPostRepairWorkerLedgerDouble();
        $first = $this->record('alpha', 31);
        $second = $this->record('alpha', 32);
        $ledger->due = $this->recordsByKey([ $first, $second ]);
        $executor = new DeletedPostRepairWorkerExecutorDouble();
        $clock = new DeletedPostRepairWorkerClock($this->now, [ 0.0, 0.0, 0.0, 11.0 ]);
        $signals = 0;

        $result = $this->worker($ledger, $executor, $clock, $signals)
            ->runAutomatically(20, 10);

        self::assertCount(1, $ledger->automaticClaims);
        self::assertCount(1, $executor->calls);
        self::assertSame('time_budget', $result->getStopReason());
        self::assertTrue($result->hasMoreDue());
        self::assertSame(1, $signals);
    }

    public function test_batch_limit_is_confirmed_by_a_final_due_read(): void
    {
        $client = new DeletedPostRepairWorkerClient('alpha');
        $this->registry->register($client);
        $ledger = new DeletedPostRepairWorkerLedgerDouble();
        $ledger->due = $this->recordsByKey([
            $this->record('alpha', 41),
            $this->record('alpha', 42),
        ]);
        $executor = new DeletedPostRepairWorkerExecutorDouble();
        $signals = 0;

        $result = $this->worker($ledger, $executor, null, $signals)->runAutomatically(1, 10);

        self::assertSame('batch_limit', $result->getStopReason());
        self::assertTrue($result->hasMoreDue());
        self::assertCount(1, $result->getItems());
    }

    public function test_disabled_client_can_run_explicit_due_batch_through_manual_claims(): void
    {
        $client = new DeletedPostRepairWorkerClient('manual-client');
        $this->registry->register($client, false);
        $ledger = new DeletedPostRepairWorkerLedgerDouble();
        $record = $this->record('manual-client', 51);
        $ledger->due = $this->recordsByKey([ $record ]);
        $executor = new DeletedPostRepairWorkerExecutorDouble();
        $signals = 0;

        $result = $this->worker($ledger, $executor, null, $signals)
            ->runForClient($client, 20, 10);

        self::assertSame([], $ledger->automaticClaims);
        self::assertSame([ $record->getIdentity()->getKey() ], $ledger->manualClaims);
        self::assertSame('manual', $executor->calls[0][2]);
        self::assertSame('complete', $result->getStopReason());
    }

    public function test_retention_runs_only_beyond_strict_boundary_and_uses_same_batch_bound(): void
    {
        $atBoundary = new DeletedPostRepairWorkerLedgerDouble();
        $atBoundary->oldestResolvedAt = $this->now->modify('-30 days');
        $executor = new DeletedPostRepairWorkerExecutorDouble();
        $signals = 0;
        $this->worker($atBoundary, $executor, null, $signals)->runAutomatically(7, 10);
        self::assertSame([], $atBoundary->purgeCalls);

        $older = new DeletedPostRepairWorkerLedgerDouble();
        $older->oldestResolvedAt = $this->now->modify('-30 days -1 second');
        $older->purgeResult = 1;
        $this->worker($older, $executor, null, $signals)->runAutomatically(7, 10);

        self::assertCount(1, $older->purgeCalls);
        self::assertSame('2026-08-22 10:00:00', $older->purgeCalls[0][0]->format('Y-m-d H:i:s'));
        self::assertSame(7, $older->purgeCalls[0][1]);
    }

    public function test_ledger_uncertainty_stops_later_records_but_still_requests_reconciliation(): void
    {
        $client = new DeletedPostRepairWorkerClient('alpha');
        $this->registry->register($client);
        $ledger = new DeletedPostRepairWorkerLedgerDouble();
        $first = $this->record('alpha', 61);
        $second = $this->record('alpha', 62);
        $ledger->due = $this->recordsByKey([ $first, $second ]);
        $executor = new DeletedPostRepairWorkerExecutorDouble();
        $executor->throwOn = $first->getIdentity()->getKey();
        $signals = 0;

        try {
            $this->worker($ledger, $executor, null, $signals)->runAutomatically();
            self::fail('Ledger uncertainty must propagate.');
        } catch (RuntimeException $failure) {
            self::assertSame('ledger transition uncertain', $failure->getMessage());
        }

        self::assertCount(1, $executor->calls);
        self::assertSame(1, $signals);
    }

    /**
     * @dataProvider invalid_bound_provider
     */
    public function test_invalid_bounds_fail_before_ledger_access(int $limit, int $budget): void
    {
        $ledger = new DeletedPostRepairWorkerLedgerDouble();
        $executor = new DeletedPostRepairWorkerExecutorDouble();
        $signals = 0;

        try {
            $this->worker($ledger, $executor, null, $signals)->runAutomatically($limit, $budget);
            self::fail('Invalid worker bounds must fail.');
        } catch (InvalidArgumentException $failure) {
            self::assertNotSame('', $failure->getMessage());
        }

        self::assertSame([], $ledger->eligibleQueries);
        self::assertSame([], $ledger->purgeCalls);
        self::assertSame(0, $signals);
    }

    public function invalid_bound_provider(): array
    {
        return [
            'zero limit' => [ 0, 10 ],
            'large limit' => [ 101, 10 ],
            'zero budget' => [ 20, 0 ],
            'large budget' => [ 20, 61 ],
        ];
    }

    private function worker(
        DeletedPostRepairWorkerLedgerDouble $ledger,
        DeletedPostRepairWorkerExecutorDouble $executor,
        ?DeletedPostRepairWorkerClock $clock,
        int &$signals
    ): DeletedPostRepairWorker {
        $clock = $clock ?? new DeletedPostRepairWorkerClock($this->now);

        return new DeletedPostRepairWorker(
            $ledger,
            $this->registry,
            $executor,
            new DeletedPostRepairPolicy($clock),
            static function () use (&$signals): void {
                $signals++;
            }
        );
    }

    private function record(string $clientName, int $postId): DeletedPostRepairRecord
    {
        $identity = new DeletedPostRepairIdentity(
            1,
            'wp_',
            $clientName,
            'delete_post_connections:v1',
            $postId
        );

        return new DeletedPostRepairRecord(
            $identity,
            DeletedPostRepairWorkerStorage::class,
            hash('sha256', DeletedPostRepairWorkerStorage::class),
            DeletedPostRepairStatus::ARMED,
            0,
            0,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $this->now,
            $this->now,
            null
        );
    }

    /**
     * @param DeletedPostRepairRecord[] $records
     * @return array<string, DeletedPostRepairRecord>
     */
    private function recordsByKey(array $records): array
    {
        $keyed = [];
        foreach ($records as $record) {
            $keyed[ $record->getIdentity()->getKey() ] = $record;
        }

        return $keyed;
    }
}
