<?php

namespace iTRON\wpConnections\Tests;

use DateTimeImmutable;
use DateTimeZone;
use iTRON\wpConnections\Client;
use iTRON\wpConnections\Internal\DeletedPostRepairAutomaticRunnerInterface;
use iTRON\wpConnections\Internal\DeletedPostRepairAutomaticWakeup;
use iTRON\wpConnections\Internal\DeletedPostRepairClientRegistry;
use iTRON\wpConnections\Internal\DeletedPostRepairClockInterface;
use iTRON\wpConnections\Internal\DeletedPostRepairCronGateway;
use iTRON\wpConnections\Internal\DeletedPostRepairDiagnostic;
use iTRON\wpConnections\Internal\DeletedPostRepairPolicy;
use iTRON\wpConnections\Internal\DeletedPostRepairReconciler;
use iTRON\wpConnections\Internal\DeletedPostRepairReconcilerLedgerInterface;
use iTRON\wpConnections\Internal\DeletedPostRepairSchedulerInterface;
use iTRON\wpConnections\Internal\DeletedPostRepairWorkerResult;
use iTRON\wpHooksDispatcher\Contracts\SiteContextProvider;
use iTRON\wpHooksDispatcher\SiteContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DeletedPostRepairReconcilerClock implements DeletedPostRepairClockInterface
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

final class DeletedPostRepairReconcilerContextProvider implements SiteContextProvider
{
    public function current(): SiteContext
    {
        return new SiteContext(1, 'wp_');
    }
}

final class DeletedPostRepairReconcilerClient extends Client
{
    public function __construct(private string $testName)
    {
        DeletedPostRepairTestClientContext::initialize($this);
    }

    public function getName(): string
    {
        return $this->testName;
    }
}

final class DeletedPostRepairReconcilerLedgerDouble implements DeletedPostRepairReconcilerLedgerInterface
{
    public ?DeletedPostRepairAutomaticWakeup $automaticWakeup = null;
    public ?DateTimeImmutable $oldestResolvedAt = null;
    public array $eligibleQueries = [];
    public array $wakeupFailures = [];

    public function findNextAutomaticWakeupForClients(
        array $clientNames,
        DateTimeImmutable $now
    ): ?DeletedPostRepairAutomaticWakeup {
        sort($clientNames, SORT_STRING);
        $this->eligibleQueries[] = $clientNames;

        return $this->automaticWakeup;
    }

    public function findOldestResolvedAt(): ?DateTimeImmutable
    {
        return $this->oldestResolvedAt;
    }

    public function recordWakeupFailure(
        string $repairKey,
        DeletedPostRepairDiagnostic $failure,
        DateTimeImmutable $now
    ): bool {
        $this->wakeupFailures[] = [ $repairKey, $failure, $now ];

        return true;
    }
}

final class DeletedPostRepairSchedulerDouble implements DeletedPostRepairSchedulerInterface
{
    public ?DateTimeImmutable $scheduledAt = null;
    public bool $automaticDispatchAvailable = true;
    public array $operations = [];
    public ?RuntimeException $scheduleFailure = null;
    public ?RuntimeException $unscheduleFailure = null;
    public ?RuntimeException $nextFailure = null;

    public function nextWakeupAt(): ?DateTimeImmutable
    {
        $this->operations[] = 'next';
        if (null !== $this->nextFailure) {
            throw $this->nextFailure;
        }

        return $this->scheduledAt;
    }

    public function scheduleWakeup(DateTimeImmutable $at): void
    {
        $this->operations[] = 'schedule:' . $at->format('Y-m-d H:i:s');
        if (null !== $this->scheduleFailure) {
            throw $this->scheduleFailure;
        }
        $this->scheduledAt = $at;
    }

    public function unscheduleWakeup(DateTimeImmutable $at): void
    {
        $this->operations[] = 'unschedule:' . $at->format('Y-m-d H:i:s');
        if (null !== $this->unscheduleFailure) {
            throw $this->unscheduleFailure;
        }
        if ($this->scheduledAt == $at) {
            $this->scheduledAt = null;
        }
    }

    public function isAutomaticDispatchAvailable(): bool
    {
        return $this->automaticDispatchAvailable;
    }
}

final class DeletedPostRepairAutomaticRunnerDouble implements DeletedPostRepairAutomaticRunnerInterface
{
    public int $calls = 0;
    public ?RuntimeException $failure = null;

    public function runAutomatically(
        int $limit = 20,
        int $timeBudgetSeconds = 10
    ): DeletedPostRepairWorkerResult {
        $this->calls++;
        if (null !== $this->failure) {
            throw $this->failure;
        }

        return new DeletedPostRepairWorkerResult([], false, 'complete', 0);
    }
}

final class DeletedPostRepairReconcilerTest extends TestCase
{
    private DateTimeImmutable $now;
    private DeletedPostRepairClientRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = new DateTimeImmutable('2026-09-21 10:00:00', new DateTimeZone('UTC'));
        $this->registry = new DeletedPostRepairClientRegistry(
            new DeletedPostRepairReconcilerContextProvider()
        );
    }

    public function test_reconciliation_uses_enabled_clients_and_earliest_cleanup_deadline(): void
    {
        $this->registry->register(new DeletedPostRepairReconcilerClient('alpha'));
        $this->registry->register(new DeletedPostRepairReconcilerClient('disabled'), false);
        $ledger = new DeletedPostRepairReconcilerLedgerDouble();
        $ledger->automaticWakeup = new DeletedPostRepairAutomaticWakeup(
            str_repeat('a', 64),
            $this->now->modify('+5 minutes')
        );
        $scheduler = new DeletedPostRepairSchedulerDouble();

        $result = $this->reconciler($ledger, $scheduler)->reconcile();

        self::assertSame([ 'alpha' ], $ledger->eligibleQueries[0]);
        self::assertSame('scheduled', $result->getOutcome());
        self::assertSame('2026-09-21 10:05:00', $result->getDesiredAt()->format('Y-m-d H:i:s'));
        self::assertSame([ 'next', 'schedule:2026-09-21 10:05:00' ], $scheduler->operations);
        self::assertTrue($result->isAutomaticDispatchAvailable());

        $again = $this->reconciler($ledger, $scheduler)->reconcile();
        self::assertSame('kept', $again->getOutcome());
        self::assertSame(
            [ 'next', 'schedule:2026-09-21 10:05:00', 'next' ],
            $scheduler->operations
        );
    }

    public function test_retention_can_schedule_without_any_eligible_client_and_competes_by_earliest_time(): void
    {
        $ledger = new DeletedPostRepairReconcilerLedgerDouble();
        $ledger->oldestResolvedAt = $this->now->modify('-30 days +2 minutes');
        $scheduler = new DeletedPostRepairSchedulerDouble();

        $result = $this->reconciler($ledger, $scheduler)->reconcile();

        self::assertSame([], $ledger->eligibleQueries[0]);
        self::assertSame('scheduled', $result->getOutcome());
        self::assertSame('2026-09-21 10:02:01', $result->getDesiredAt()->format('Y-m-d H:i:s'));
    }

    public function test_existing_earlier_event_is_kept_and_earlier_replacement_is_scheduled_before_old_removal(): void
    {
        $ledger = new DeletedPostRepairReconcilerLedgerDouble();
        $ledger->automaticWakeup = new DeletedPostRepairAutomaticWakeup(
            str_repeat('b', 64),
            $this->now->modify('+10 minutes')
        );
        $scheduler = new DeletedPostRepairSchedulerDouble();
        $scheduler->scheduledAt = $this->now->modify('+5 minutes');

        self::assertSame('kept', $this->reconciler($ledger, $scheduler)->reconcile()->getOutcome());
        self::assertSame([ 'next' ], $scheduler->operations);

        $ledger->automaticWakeup = new DeletedPostRepairAutomaticWakeup(
            str_repeat('b', 64),
            $this->now->modify('+2 minutes')
        );
        $scheduler->operations = [];
        $result = $this->reconciler($ledger, $scheduler)->reconcile();

        self::assertSame('scheduled', $result->getOutcome());
        self::assertSame([
            'next',
            'schedule:2026-09-21 10:02:00',
            'unschedule:2026-09-21 10:05:00',
        ], $scheduler->operations);
    }

    public function test_scheduler_failure_is_redacted_persisted_and_never_removes_existing_event(): void
    {
        $ledger = new DeletedPostRepairReconcilerLedgerDouble();
        $ledger->automaticWakeup = new DeletedPostRepairAutomaticWakeup(
            str_repeat('c', 64),
            $this->now
        );
        $scheduler = new DeletedPostRepairSchedulerDouble();
        $scheduler->scheduledAt = $this->now->modify('+20 minutes');
        $scheduler->scheduleFailure = new RuntimeException('private cron option payload', 73);

        $result = $this->reconciler($ledger, $scheduler)->reconcile();

        self::assertSame('failed', $result->getOutcome());
        self::assertSame([ 'next', 'schedule:2026-09-21 10:00:00' ], $scheduler->operations);
        self::assertEquals($this->now->modify('+20 minutes'), $scheduler->scheduledAt);
        self::assertSame(str_repeat('c', 64), $ledger->wakeupFailures[0][0]);
        self::assertSame('scheduler', $ledger->wakeupFailures[0][1]->getCategory());
        self::assertSame('[diagnostic details redacted]', $ledger->wakeupFailures[0][1]->getSummary());
        self::assertStringNotContainsString('private cron', $result->getDiagnostic()->getSummary());
    }

    public function test_no_work_removes_a_stale_event_and_disabled_dispatch_is_explicit(): void
    {
        $ledger = new DeletedPostRepairReconcilerLedgerDouble();
        $scheduler = new DeletedPostRepairSchedulerDouble();
        $scheduler->scheduledAt = $this->now->modify('+20 minutes');
        $scheduler->automaticDispatchAvailable = false;

        $result = $this->reconciler($ledger, $scheduler)->reconcile();

        self::assertSame('unscheduled', $result->getOutcome());
        self::assertFalse($result->isAutomaticDispatchAvailable());
        self::assertSame([
            'next',
            'unschedule:2026-09-21 10:20:00',
        ], $scheduler->operations);
    }

    public function test_failed_event_read_preserves_observed_dispatch_availability(): void
    {
        $ledger = new DeletedPostRepairReconcilerLedgerDouble();
        $scheduler = new DeletedPostRepairSchedulerDouble();
        $scheduler->nextFailure = new RuntimeException('private cron read failure');

        $result = $this->reconciler($ledger, $scheduler)->reconcile();

        self::assertSame('failed', $result->getOutcome());
        self::assertTrue($result->isAutomaticDispatchAvailable());
        self::assertStringNotContainsString(
            'private cron',
            $result->getDiagnostic()->getSummary()
        );
    }

    public function test_failed_stale_event_cleanup_preserves_the_new_earlier_wakeup(): void
    {
        $ledger = new DeletedPostRepairReconcilerLedgerDouble();
        $ledger->automaticWakeup = new DeletedPostRepairAutomaticWakeup(
            str_repeat('d', 64),
            $this->now
        );
        $scheduler = new DeletedPostRepairSchedulerDouble();
        $scheduler->scheduledAt = $this->now->modify('+20 minutes');
        $scheduler->unscheduleFailure = new RuntimeException('private stale event');

        $result = $this->reconciler($ledger, $scheduler)->reconcile();

        self::assertSame('scheduled_with_duplicate', $result->getOutcome());
        self::assertSame($this->now, $scheduler->scheduledAt);
        self::assertNotNull($result->getDiagnostic());
    }

    public function test_cron_gateway_reconciles_after_success_and_preserves_primary_failure(): void
    {
        $runner = new DeletedPostRepairAutomaticRunnerDouble();
        $signals = 0;
        $gateway = new DeletedPostRepairCronGateway(
            $runner,
            static function () use (&$signals): void {
                $signals++;
            }
        );

        self::assertSame('complete', $gateway->run()->getStopReason());
        self::assertSame(1, $runner->calls);
        self::assertSame(1, $signals);

        $runner->failure = new RuntimeException('primary ledger uncertainty');
        try {
            $gateway->run();
            self::fail('The primary worker failure must propagate.');
        } catch (RuntimeException $failure) {
            self::assertSame('primary ledger uncertainty', $failure->getMessage());
        }
        self::assertSame(2, $runner->calls);
        self::assertSame(2, $signals);
    }

    private function reconciler(
        DeletedPostRepairReconcilerLedgerDouble $ledger,
        DeletedPostRepairSchedulerDouble $scheduler
    ): DeletedPostRepairReconciler {
        return new DeletedPostRepairReconciler(
            $ledger,
            $this->registry,
            $scheduler,
            new DeletedPostRepairPolicy(new DeletedPostRepairReconcilerClock($this->now))
        );
    }
}
