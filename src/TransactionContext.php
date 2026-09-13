<?php

namespace iTRON\wpConnections;

use InvalidArgumentException;

final class TransactionContext
{
    public const ROOT = 'root';
    public const NESTED = 'nested';

    private string $mode;
    private ?TransactionSynchronizer $synchronizer;
    private bool $schemaRecoveryAllowed;

    private function __construct(
        string $mode,
        ?TransactionSynchronizer $synchronizer,
        bool $schemaRecoveryAllowed
    ) {
        if (! in_array($mode, [ self::ROOT, self::NESTED ], true)) {
            throw new InvalidArgumentException('Unknown transaction context.');
        }

        $this->mode = $mode;
        $this->synchronizer = $synchronizer;
        $this->schemaRecoveryAllowed = $schemaRecoveryAllowed;
    }

    public static function root(): self
    {
        return new self(self::ROOT, null, true);
    }

    /**
     * @internal Non-create mutations must not trigger lazy schema recovery.
     */
    public static function strictRoot(): self
    {
        return new self(self::ROOT, null, false);
    }

    public static function nested(TransactionSynchronizer $synchronizer): self
    {
        return new self(self::NESTED, $synchronizer, false);
    }

    /**
     * @internal Used for a child scope inside a library-owned unit of work.
     */
    public static function libraryNested(): self
    {
        return new self(self::NESTED, null, false);
    }

    public function isRoot(): bool
    {
        return self::ROOT === $this->mode;
    }

    public function isNested(): bool
    {
        return self::NESTED === $this->mode;
    }

    public function getSynchronizer(): ?TransactionSynchronizer
    {
        return $this->synchronizer;
    }

    /**
     * @internal Concrete adapters use this only before transaction DML.
     */
    public function isSchemaRecoveryAllowed(): bool
    {
        return $this->schemaRecoveryAllowed;
    }
}
