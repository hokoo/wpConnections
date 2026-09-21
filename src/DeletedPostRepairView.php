<?php

namespace iTRON\wpConnections;

use DateTimeImmutable;
use InvalidArgumentException;

final class DeletedPostRepairView
{
    private string $repairKey;
    private int $postId;
    private string $status;
    private int $attemptCount;
    private int $failureCount;
    private ?DateTimeImmutable $nextAttemptAt;
    private ?string $failureCategory;
    private ?string $failureClass;
    private ?string $failureCode;
    private ?string $failureSummary;
    private ?DateTimeImmutable $firstFailureAt;
    private ?DateTimeImmutable $lastFailureAt;
    private ?string $wakeupFailureCategory;
    private ?string $wakeupFailureSummary;
    private ?DateTimeImmutable $wakeupFailureAt;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $updatedAt;
    private ?DateTimeImmutable $resolvedAt;

    private function __construct(
        string $repairKey,
        int $postId,
        string $status,
        int $attemptCount,
        int $failureCount,
        ?DateTimeImmutable $nextAttemptAt,
        ?string $failureCategory,
        ?string $failureClass,
        ?string $failureCode,
        ?string $failureSummary,
        ?DateTimeImmutable $firstFailureAt,
        ?DateTimeImmutable $lastFailureAt,
        ?string $wakeupFailureCategory,
        ?string $wakeupFailureSummary,
        ?DateTimeImmutable $wakeupFailureAt,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        ?DateTimeImmutable $resolvedAt
    ) {
        if (! preg_match('/^[a-f0-9]{64}$/D', $repairKey) || 0 >= $postId) {
            throw new InvalidArgumentException('Invalid deleted-post repair projection identity.');
        }
        if (0 > $attemptCount || 0 > $failureCount || $attemptCount < $failureCount) {
            throw new InvalidArgumentException('Invalid deleted-post repair projection counters.');
        }

        $this->repairKey = $repairKey;
        $this->postId = $postId;
        $this->status = $status;
        $this->attemptCount = $attemptCount;
        $this->failureCount = $failureCount;
        $this->nextAttemptAt = $nextAttemptAt;
        $this->failureCategory = $failureCategory;
        $this->failureClass = $failureClass;
        $this->failureCode = $failureCode;
        $this->failureSummary = $failureSummary;
        $this->firstFailureAt = $firstFailureAt;
        $this->lastFailureAt = $lastFailureAt;
        $this->wakeupFailureCategory = $wakeupFailureCategory;
        $this->wakeupFailureSummary = $wakeupFailureSummary;
        $this->wakeupFailureAt = $wakeupFailureAt;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->resolvedAt = $resolvedAt;
    }

    /**
     * @internal Library-owned construction path; not part of the compatibility surface.
     */
    public static function createInternal(
        string $repairKey,
        int $postId,
        string $status,
        int $attemptCount,
        int $failureCount,
        ?DateTimeImmutable $nextAttemptAt,
        ?string $failureCategory,
        ?string $failureClass,
        ?string $failureCode,
        ?string $failureSummary,
        ?DateTimeImmutable $firstFailureAt,
        ?DateTimeImmutable $lastFailureAt,
        ?string $wakeupFailureCategory,
        ?string $wakeupFailureSummary,
        ?DateTimeImmutable $wakeupFailureAt,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        ?DateTimeImmutable $resolvedAt
    ): self {
        return new self(
            $repairKey,
            $postId,
            $status,
            $attemptCount,
            $failureCount,
            $nextAttemptAt,
            $failureCategory,
            $failureClass,
            $failureCode,
            $failureSummary,
            $firstFailureAt,
            $lastFailureAt,
            $wakeupFailureCategory,
            $wakeupFailureSummary,
            $wakeupFailureAt,
            $createdAt,
            $updatedAt,
            $resolvedAt
        );
    }

    public function getRepairKey(): string
    {
        return $this->repairKey;
    }

    public function getPostId(): int
    {
        return $this->postId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getAttemptCount(): int
    {
        return $this->attemptCount;
    }

    public function getFailureCount(): int
    {
        return $this->failureCount;
    }

    public function getNextAttemptAt(): ?DateTimeImmutable
    {
        return $this->nextAttemptAt;
    }

    public function getFailureCategory(): ?string
    {
        return $this->failureCategory;
    }

    public function getFailureClass(): ?string
    {
        return $this->failureClass;
    }

    public function getFailureCode(): ?string
    {
        return $this->failureCode;
    }

    public function getFailureSummary(): ?string
    {
        return $this->failureSummary;
    }

    public function getFirstFailureAt(): ?DateTimeImmutable
    {
        return $this->firstFailureAt;
    }

    public function getLastFailureAt(): ?DateTimeImmutable
    {
        return $this->lastFailureAt;
    }

    public function getWakeupFailureCategory(): ?string
    {
        return $this->wakeupFailureCategory;
    }

    public function getWakeupFailureSummary(): ?string
    {
        return $this->wakeupFailureSummary;
    }

    public function getWakeupFailureAt(): ?DateTimeImmutable
    {
        return $this->wakeupFailureAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getResolvedAt(): ?DateTimeImmutable
    {
        return $this->resolvedAt;
    }
}
