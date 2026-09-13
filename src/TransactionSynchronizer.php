<?php

namespace iTRON\wpConnections;

use LogicException;

final class TransactionSynchronizer
{
    private const PENDING = 'pending';
    private const COMMITTED = 'committed';
    private const ROLLED_BACK = 'rolled_back';

    private string $state = self::PENDING;
    private array $notifications = [];

    /**
     * @internal Client transfers committed-success notifications here.
     */
    public function defer(callable $notification): void
    {
        if (self::PENDING !== $this->state) {
            throw new LogicException('Transaction synchronization has already completed.');
        }

        $this->notifications[] = $notification;
    }

    public function committed(): void
    {
        $this->assertPending();
        $this->state = self::COMMITTED;

        foreach ($this->notifications as $notification) {
            $notification();
        }
    }

    public function rolledBack(): void
    {
        $this->assertPending();
        $this->state = self::ROLLED_BACK;
        $this->notifications = [];
    }

    /**
     * @internal Client uses this for pre-mutation capability validation.
     */
    public function isPending(): bool
    {
        return self::PENDING === $this->state;
    }

    private function assertPending(): void
    {
        if (self::PENDING !== $this->state) {
            throw new LogicException('Transaction synchronization has already completed.');
        }
    }
}
