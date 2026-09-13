<?php

namespace iTRON\wpConnections\Internal;

use DateTimeImmutable;

final class DeletedPostRepairRecord
{
    private DeletedPostRepairIdentity $identity;
    private string $storageClass;
    private string $storageFingerprint;
    private string $status;
    private int $attemptCount;
    private int $failureCount;
    private ?DateTimeImmutable $nextAttemptAt;
    private ?string $leaseToken;
    private ?DateTimeImmutable $leaseExpiresAt;
    private ?DeletedPostRepairDiagnostic $failureDiagnostic;
    private ?DateTimeImmutable $firstFailureAt;
    private ?DateTimeImmutable $lastFailureAt;
    private ?DeletedPostRepairDiagnostic $wakeupDiagnostic;
    private ?DateTimeImmutable $wakeupFailureAt;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $updatedAt;
    private ?DateTimeImmutable $resolvedAt;

    public function __construct(
        DeletedPostRepairIdentity $identity,
        string $storageClass,
        string $storageFingerprint,
        string $status,
        int $attemptCount,
        int $failureCount,
        ?DateTimeImmutable $nextAttemptAt,
        ?string $leaseToken,
        ?DateTimeImmutable $leaseExpiresAt,
        ?DeletedPostRepairDiagnostic $failureDiagnostic,
        ?DateTimeImmutable $firstFailureAt,
        ?DateTimeImmutable $lastFailureAt,
        ?DeletedPostRepairDiagnostic $wakeupDiagnostic,
        ?DateTimeImmutable $wakeupFailureAt,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        ?DateTimeImmutable $resolvedAt
    ) {
        DeletedPostRepairStatus::assertValid($status);
        $this->identity = $identity;
        $this->storageClass = $storageClass;
        $this->storageFingerprint = $storageFingerprint;
        $this->status = $status;
        $this->attemptCount = $attemptCount;
        $this->failureCount = $failureCount;
        $this->nextAttemptAt = $nextAttemptAt;
        $this->leaseToken = $leaseToken;
        $this->leaseExpiresAt = $leaseExpiresAt;
        $this->failureDiagnostic = $failureDiagnostic;
        $this->firstFailureAt = $firstFailureAt;
        $this->lastFailureAt = $lastFailureAt;
        $this->wakeupDiagnostic = $wakeupDiagnostic;
        $this->wakeupFailureAt = $wakeupFailureAt;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->resolvedAt = $resolvedAt;
    }

    public function getIdentity(): DeletedPostRepairIdentity
    {
        return $this->identity;
    }

    public function getStorageClass(): string
    {
        return $this->storageClass;
    }

    public function getStorageFingerprint(): string
    {
        return $this->storageFingerprint;
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

    public function getLeaseToken(): ?string
    {
        return $this->leaseToken;
    }

    public function getLeaseExpiresAt(): ?DateTimeImmutable
    {
        return $this->leaseExpiresAt;
    }

    public function getFailureDiagnostic(): ?DeletedPostRepairDiagnostic
    {
        return $this->failureDiagnostic;
    }

    public function getFirstFailureAt(): ?DateTimeImmutable
    {
        return $this->firstFailureAt;
    }

    public function getLastFailureAt(): ?DateTimeImmutable
    {
        return $this->lastFailureAt;
    }

    public function getWakeupDiagnostic(): ?DeletedPostRepairDiagnostic
    {
        return $this->wakeupDiagnostic;
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
