<?php

namespace iTRON\wpConnections;

use InvalidArgumentException;

final class DeletedPostRepairBatchResult
{
    private const STOP_REASONS = [ 'complete', 'batch_limit', 'time_budget' ];

    /** @var DeletedPostRepairRetryResult[] */
    private array $results;
    private bool $hasMoreDue;
    private string $stopReason;

    /**
     * @param DeletedPostRepairRetryResult[] $results
     */
    private function __construct(array $results, bool $hasMoreDue, string $stopReason)
    {
        foreach ($results as $result) {
            if (! $result instanceof DeletedPostRepairRetryResult) {
                throw new InvalidArgumentException('Repair batches contain only retry results.');
            }
        }
        if (! in_array($stopReason, self::STOP_REASONS, true)) {
            throw new InvalidArgumentException('Unknown deleted-post repair batch stop reason.');
        }
        if (('complete' === $stopReason) === $hasMoreDue) {
            throw new InvalidArgumentException('Repair batch completion and due-work state disagree.');
        }

        $this->results = array_values($results);
        $this->hasMoreDue = $hasMoreDue;
        $this->stopReason = $stopReason;
    }

    /**
     * @internal Library-owned construction path; not part of the compatibility surface.
     * @param DeletedPostRepairRetryResult[] $results
     */
    public static function createInternal(
        array $results,
        bool $hasMoreDue,
        string $stopReason
    ): self {
        return new self($results, $hasMoreDue, $stopReason);
    }

    /**
     * @return DeletedPostRepairRetryResult[]
     */
    public function getResults(): array
    {
        return $this->results;
    }

    public function hasMoreDue(): bool
    {
        return $this->hasMoreDue;
    }

    public function getStopReason(): string
    {
        return $this->stopReason;
    }
}
