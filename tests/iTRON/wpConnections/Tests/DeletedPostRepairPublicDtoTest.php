<?php

namespace iTRON\wpConnections\Tests;

use DateTimeImmutable;
use DateTimeZone;
use iTRON\wpConnections\DeletedPostRepairBatchResult;
use iTRON\wpConnections\DeletedPostRepairPage;
use iTRON\wpConnections\DeletedPostRepairRetryResult;
use iTRON\wpConnections\DeletedPostRepairView;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class DeletedPostRepairPublicDtoTest extends TestCase
{
    public function test_view_exposes_only_the_approved_safe_projection(): void
    {
        $created = $this->utc('2026-09-01 10:00:00');
        $updated = $this->utc('2026-09-02 10:00:00');
        $next = $this->utc('2026-09-03 10:00:00');
        $view = DeletedPostRepairView::createInternal(
            str_repeat('a', 64),
            417,
            'retry_wait',
            2,
            1,
            $next,
            'storage',
            'SafeFailure',
            '73',
            '[diagnostic details redacted]',
            $created,
            $updated,
            'scheduler',
            '[diagnostic details redacted]',
            $updated,
            $created,
            $updated,
            null
        );

        self::assertSame(str_repeat('a', 64), $view->getRepairKey());
        self::assertSame(417, $view->getPostId());
        self::assertSame('retry_wait', $view->getStatus());
        self::assertSame(2, $view->getAttemptCount());
        self::assertSame(1, $view->getFailureCount());
        self::assertSame($next, $view->getNextAttemptAt());
        self::assertSame('storage', $view->getFailureCategory());
        self::assertSame('SafeFailure', $view->getFailureClass());
        self::assertSame('73', $view->getFailureCode());
        self::assertSame('[diagnostic details redacted]', $view->getFailureSummary());
        self::assertSame($created, $view->getFirstFailureAt());
        self::assertSame($updated, $view->getLastFailureAt());
        self::assertSame('scheduler', $view->getWakeupFailureCategory());
        self::assertSame('[diagnostic details redacted]', $view->getWakeupFailureSummary());
        self::assertSame($updated, $view->getWakeupFailureAt());
        self::assertSame($created, $view->getCreatedAt());
        self::assertSame($updated, $view->getUpdatedAt());
        self::assertNull($view->getResolvedAt());
        self::assertFalse(property_exists($view, 'sitePrefix'));
        self::assertFalse(property_exists($view, 'storageFingerprint'));
        self::assertFalse(property_exists($view, 'leaseToken'));
    }

    public function test_page_retry_and_batch_results_are_typed_immutable_values(): void
    {
        $view = $this->view(str_repeat('b', 64));
        $page = DeletedPostRepairPage::createInternal([ $view ], str_repeat('b', 64));
        $retry = DeletedPostRepairRetryResult::createInternal(
            str_repeat('b', 64),
            'resolved',
            true,
            $view
        );
        $batch = DeletedPostRepairBatchResult::createInternal(
            [ $retry ],
            true,
            'batch_limit'
        );

        self::assertSame([ $view ], $page->getItems());
        self::assertSame(str_repeat('b', 64), $page->getNextAfterKey());
        self::assertSame(str_repeat('b', 64), $retry->getRepairKey());
        self::assertSame('resolved', $retry->getOutcome());
        self::assertTrue($retry->wasCleanupAttempted());
        self::assertSame($view, $retry->getRepair());
        self::assertSame([ $retry ], $batch->getResults());
        self::assertTrue($batch->hasMoreDue());
        self::assertSame('batch_limit', $batch->getStopReason());

        foreach ([
            DeletedPostRepairView::class,
            DeletedPostRepairPage::class,
            DeletedPostRepairRetryResult::class,
            DeletedPostRepairBatchResult::class,
        ] as $class) {
            self::assertTrue(( new ReflectionClass($class) )->getConstructor()->isPrivate());
        }
    }

    private function view(string $repairKey): DeletedPostRepairView
    {
        $now = $this->utc('2026-09-21 10:00:00');

        return DeletedPostRepairView::createInternal(
            $repairKey,
            1,
            'armed',
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
            null,
            null,
            $now,
            $now,
            null
        );
    }

    private function utc(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }
}
