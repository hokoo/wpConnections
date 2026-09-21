<?php

namespace iTRON\wpConnections\Internal;

use InvalidArgumentException;

/**
 * @internal Bounded worker result mapped to public DTOs by the operator service.
 */
final class DeletedPostRepairWorkerResult
{
    public const COMPLETE = 'complete';
    public const BATCH_LIMIT = 'batch_limit';
    public const TIME_BUDGET = 'time_budget';

    /** @var DeletedPostRepairWorkerItemResult[] */
    private array $items;
    private bool $hasMoreDue;
    private string $stopReason;
    private int $purgedResolvedCount;

    /**
     * @param DeletedPostRepairWorkerItemResult[] $items
     */
    public function __construct(
        array $items,
        bool $hasMoreDue,
        string $stopReason,
        int $purgedResolvedCount
    ) {
        foreach ($items as $item) {
            if (! $item instanceof DeletedPostRepairWorkerItemResult) {
                throw new InvalidArgumentException('Worker results must contain only item results.');
            }
        }
        if (! in_array($stopReason, [ self::COMPLETE, self::BATCH_LIMIT, self::TIME_BUDGET ], true)) {
            throw new InvalidArgumentException('Unknown repair worker stop reason.');
        }
        if ((self::COMPLETE === $stopReason) === $hasMoreDue) {
            throw new InvalidArgumentException('Repair worker completion and due-work state disagree.');
        }
        if (0 > $purgedResolvedCount) {
            throw new InvalidArgumentException('Purged repair count cannot be negative.');
        }

        $this->items = array_values($items);
        $this->hasMoreDue = $hasMoreDue;
        $this->stopReason = $stopReason;
        $this->purgedResolvedCount = $purgedResolvedCount;
    }

    /**
     * @return DeletedPostRepairWorkerItemResult[]
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function hasMoreDue(): bool
    {
        return $this->hasMoreDue;
    }

    public function getStopReason(): string
    {
        return $this->stopReason;
    }

    public function getPurgedResolvedCount(): int
    {
        return $this->purgedResolvedCount;
    }
}
