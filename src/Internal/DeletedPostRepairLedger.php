<?php

namespace iTRON\wpConnections\Internal;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use iTRON\wpConnections\Exceptions\StorageFailure;
use RuntimeException;
use Throwable;

final class DeletedPostRepairLedger
{
    private const TABLE_KEY = 'wpconnections_repair';
    private const OWNERSHIP_OPTION = 'wpconnections_repair_schema_owner';
    private const OWNER = 'hokoo/wpconnections';
    private const SCHEMA_VERSION = 1;
    private const REQUIRED_ENGINE = 'INNODB';
    private const MAX_PAGE_SIZE = 100;
    private const MAX_COUNTER = 4294967295;

    private const COLUMNS = [
        'repair_key' => [ 'type' => 'char(64)', 'nullable' => false, 'default' => null, 'extra' => '' ],
        'site_id' => [ 'type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'extra' => '' ],
        'site_prefix' => [ 'type' => 'varchar(64)', 'nullable' => false, 'default' => null, 'extra' => '' ],
        'client_name' => [ 'type' => 'varchar(191)', 'nullable' => false, 'default' => null, 'extra' => '' ],
        'storage_class' => [ 'type' => 'varchar(255)', 'nullable' => false, 'default' => null, 'extra' => '' ],
        'storage_fingerprint' => [ 'type' => 'char(64)', 'nullable' => false, 'default' => null, 'extra' => '' ],
        'operation' => [ 'type' => 'varchar(64)', 'nullable' => false, 'default' => null, 'extra' => '' ],
        'post_id' => [ 'type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'extra' => '' ],
        'status' => [ 'type' => 'varchar(32)', 'nullable' => false, 'default' => null, 'extra' => '' ],
        'attempt_count' => [ 'type' => 'int unsigned', 'nullable' => false, 'default' => '0', 'extra' => '' ],
        'failure_count' => [ 'type' => 'int unsigned', 'nullable' => false, 'default' => '0', 'extra' => '' ],
        'next_attempt_at' => [ 'type' => 'datetime', 'nullable' => true, 'default' => null, 'extra' => '' ],
        'lease_token' => [ 'type' => 'char(64)', 'nullable' => true, 'default' => null, 'extra' => '' ],
        'lease_expires_at' => [ 'type' => 'datetime', 'nullable' => true, 'default' => null, 'extra' => '' ],
        'failure_category' => [ 'type' => 'varchar(64)', 'nullable' => true, 'default' => null, 'extra' => '' ],
        'failure_class' => [ 'type' => 'varchar(255)', 'nullable' => true, 'default' => null, 'extra' => '' ],
        'failure_code' => [ 'type' => 'varchar(64)', 'nullable' => true, 'default' => null, 'extra' => '' ],
        'failure_summary' => [ 'type' => 'longtext', 'nullable' => true, 'default' => null, 'extra' => '' ],
        'first_failure_at' => [ 'type' => 'datetime', 'nullable' => true, 'default' => null, 'extra' => '' ],
        'last_failure_at' => [ 'type' => 'datetime', 'nullable' => true, 'default' => null, 'extra' => '' ],
        'wakeup_failure_category' => [ 'type' => 'varchar(64)', 'nullable' => true, 'default' => null, 'extra' => '' ],
        'wakeup_failure_summary' => [ 'type' => 'longtext', 'nullable' => true, 'default' => null, 'extra' => '' ],
        'wakeup_failure_at' => [ 'type' => 'datetime', 'nullable' => true, 'default' => null, 'extra' => '' ],
        'created_at' => [ 'type' => 'datetime', 'nullable' => false, 'default' => null, 'extra' => '' ],
        'updated_at' => [ 'type' => 'datetime', 'nullable' => false, 'default' => null, 'extra' => '' ],
        'resolved_at' => [ 'type' => 'datetime', 'nullable' => true, 'default' => null, 'extra' => '' ],
    ];

    private const INDEXES = [
        'PRIMARY' => [
            'non_unique' => 0,
            'type' => 'BTREE',
            'usable' => true,
            'columns' => [ [ 'name' => 'repair_key', 'prefix' => null ] ],
        ],
        'client_status' => [
            'non_unique' => 1,
            'type' => 'BTREE',
            'usable' => true,
            'columns' => [ [ 'name' => 'client_name', 'prefix' => null ], [ 'name' => 'status', 'prefix' => null ] ],
        ],
        'status_due' => [
            'non_unique' => 1,
            'type' => 'BTREE',
            'usable' => true,
            'columns' => [ [ 'name' => 'status', 'prefix' => null ], [ 'name' => 'next_attempt_at', 'prefix' => null ] ],
        ],
        'status_lease' => [
            'non_unique' => 1,
            'type' => 'BTREE',
            'usable' => true,
            'columns' => [ [ 'name' => 'status', 'prefix' => null ], [ 'name' => 'lease_expires_at', 'prefix' => null ] ],
        ],
        'status_resolved' => [
            'non_unique' => 1,
            'type' => 'BTREE',
            'usable' => true,
            'columns' => [ [ 'name' => 'status', 'prefix' => null ], [ 'name' => 'resolved_at', 'prefix' => null ] ],
        ],
    ];

    private object $database;
    private int $siteId;
    private string $sitePrefix;
    private string $tableName;
    private string $optionsTable;

    public function __construct()
    {
        global $wpdb;

        $this->database = $wpdb;
        $this->siteId = (int) get_current_blog_id();
        $this->sitePrefix = (string) $wpdb->prefix;
        $this->tableName = $this->sitePrefix . self::TABLE_KEY;
        $this->optionsTable = (string) $wpdb->options;
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    public function ensureReady(): void
    {
        $this->assertContextAndIdentifier();
        $tableExists = $this->tableExists();
        $ownership = $this->readOwnershipRecord();

        if ($tableExists) {
            if (null === $ownership) {
                throw $this->failure('verify repair ledger ownership: existing table is unowned');
            }

            $this->assertOwnership($ownership);
            $this->assertSchemaReady();
            $this->registerTable();
            return;
        }

        if (null === $ownership) {
            $this->claimOwnership();
        } else {
            $this->assertOwnership($ownership);
        }

        $this->createTable();
        $this->assertSchemaReady();
        $this->registerTable();
    }

    public function assertReady(): void
    {
        $this->assertContextAndIdentifier();
        if (! $this->tableExists()) {
            throw $this->failure('verify repair ledger schema: table is missing');
        }

        $ownership = $this->readOwnershipRecord();
        if (null === $ownership) {
            throw $this->failure('verify repair ledger ownership: record is missing');
        }

        $this->assertOwnership($ownership);
        $this->assertSchemaReady();
        $this->registerTable();
    }

    public function armAndTryClaim(
        DeletedPostRepairIdentity $identity,
        object $storage,
        DateTimeImmutable $now,
        DateTimeImmutable $leaseUntil
    ): DeletedPostRepairClaimResult {
        $this->assertReady();
        $this->assertIdentityContext($identity);
        $this->assertLeaseWindow($now, $leaseUntil);
        [ $storageClass, $storageFingerprint ] = $this->storageIdentity($storage);
        $timestamp = $this->formatTime($now);

        global $wpdb;
        $query = $wpdb->prepare(
            "INSERT INTO `{$this->tableName}`
                (`repair_key`, `site_id`, `site_prefix`, `client_name`, `storage_class`, `storage_fingerprint`,
                 `operation`, `post_id`, `status`, `attempt_count`, `failure_count`, `created_at`, `updated_at`)
             VALUES (%s, %d, %s, %s, %s, %s, %s, %d, %s, 0, 0, %s, %s)
             ON DUPLICATE KEY UPDATE `repair_key` = VALUES(`repair_key`)",
            $identity->getKey(),
            $identity->getSiteId(),
            $identity->getSitePrefix(),
            $identity->getClientName(),
            $storageClass,
            $storageFingerprint,
            $identity->getOperation(),
            $identity->getPostId(),
            DeletedPostRepairStatus::ARMED,
            $timestamp,
            $timestamp
        );
        $this->mutationOrFail($query, 'arm deleted-post repair');

        $record = $this->findByKey($identity->getKey());
        if (null === $record || $record->getIdentity()->getKey() !== $identity->getKey()) {
            throw $this->failure('verify armed deleted-post repair identity');
        }

        return $this->tryClaim($identity->getKey(), $storageFingerprint, $now, $leaseUntil, false);
    }

    public function tryClaimDue(
        string $repairKey,
        object $storage,
        DateTimeImmutable $now,
        DateTimeImmutable $leaseUntil
    ): DeletedPostRepairClaimResult {
        $this->assertReady();
        $this->assertRepairKey($repairKey);
        $this->assertLeaseWindow($now, $leaseUntil);
        [ , $storageFingerprint ] = $this->storageIdentity($storage);

        return $this->tryClaim($repairKey, $storageFingerprint, $now, $leaseUntil, false);
    }

    public function tryClaimManually(
        string $repairKey,
        object $storage,
        DateTimeImmutable $now,
        DateTimeImmutable $leaseUntil
    ): DeletedPostRepairClaimResult {
        $this->assertReady();
        $this->assertRepairKey($repairKey);
        $this->assertLeaseWindow($now, $leaseUntil);
        [ , $storageFingerprint ] = $this->storageIdentity($storage);

        return $this->tryClaim($repairKey, $storageFingerprint, $now, $leaseUntil, true);
    }

    public function markRetryWait(
        DeletedPostRepairLease $lease,
        DeletedPostRepairDiagnostic $failure,
        DateTimeImmutable $nextAttemptAt,
        DateTimeImmutable $now
    ): bool {
        $this->assertReady();
        $this->assertUtc($now);
        $this->assertUtc($nextAttemptAt);
        if ($nextAttemptAt < $now) {
            throw new InvalidArgumentException('The next repair attempt cannot be in the past.');
        }

        global $wpdb;
        $query = $wpdb->prepare(
            "UPDATE `{$this->tableName}` SET
                `status` = %s,
                `failure_count` = `failure_count` + 1,
                `next_attempt_at` = %s,
                `lease_token` = NULL,
                `lease_expires_at` = NULL,
                `failure_category` = %s,
                `failure_class` = %s,
                `failure_code` = %s,
                `failure_summary` = %s,
                `first_failure_at` = COALESCE(`first_failure_at`, %s),
                `last_failure_at` = %s,
                `updated_at` = %s,
                `resolved_at` = NULL
             WHERE `repair_key` = %s AND `status` = %s AND `lease_token` = %s
                AND `failure_count` < " . self::MAX_COUNTER . " AND `updated_at` <= %s",
            DeletedPostRepairStatus::RETRY_WAIT,
            $this->formatTime($nextAttemptAt),
            $failure->getCategory(),
            $failure->getClass(),
            $failure->getCode(),
            $failure->getSummary(),
            $this->formatTime($now),
            $this->formatTime($now),
            $this->formatTime($now),
            $lease->getRepairKey(),
            DeletedPostRepairStatus::RUNNING,
            $lease->getToken(),
            $this->formatTime($now)
        );

        return $this->failureTransitionResult(
            $this->mutationOrFail($query, 'record deleted-post repair retry failure'),
            $lease,
            'record deleted-post repair retry failure'
        );
    }

    public function markNeedsAttention(
        DeletedPostRepairLease $lease,
        DeletedPostRepairDiagnostic $failure,
        DateTimeImmutable $now
    ): bool {
        $this->assertReady();
        $this->assertUtc($now);

        global $wpdb;
        $query = $wpdb->prepare(
            "UPDATE `{$this->tableName}` SET
                `status` = %s,
                `failure_count` = `failure_count` + 1,
                `next_attempt_at` = NULL,
                `lease_token` = NULL,
                `lease_expires_at` = NULL,
                `failure_category` = %s,
                `failure_class` = %s,
                `failure_code` = %s,
                `failure_summary` = %s,
                `first_failure_at` = COALESCE(`first_failure_at`, %s),
                `last_failure_at` = %s,
                `updated_at` = %s,
                `resolved_at` = NULL
             WHERE `repair_key` = %s AND `status` = %s AND `lease_token` = %s
                AND `failure_count` < " . self::MAX_COUNTER . " AND `updated_at` <= %s",
            DeletedPostRepairStatus::NEEDS_ATTENTION,
            $failure->getCategory(),
            $failure->getClass(),
            $failure->getCode(),
            $failure->getSummary(),
            $this->formatTime($now),
            $this->formatTime($now),
            $this->formatTime($now),
            $lease->getRepairKey(),
            DeletedPostRepairStatus::RUNNING,
            $lease->getToken(),
            $this->formatTime($now)
        );

        return $this->failureTransitionResult(
            $this->mutationOrFail($query, 'record deleted-post repair attention failure'),
            $lease,
            'record deleted-post repair attention failure'
        );
    }

    public function markResolved(DeletedPostRepairLease $lease, DateTimeImmutable $now): bool
    {
        $this->assertReady();
        $this->assertUtc($now);

        global $wpdb;
        $query = $wpdb->prepare(
            "UPDATE `{$this->tableName}` SET
                `status` = %s,
                `next_attempt_at` = NULL,
                `lease_token` = NULL,
                `lease_expires_at` = NULL,
                `updated_at` = %s,
                `resolved_at` = %s
             WHERE `repair_key` = %s AND `status` = %s AND `lease_token` = %s
                AND `failure_count` > 0 AND `updated_at` <= %s",
            DeletedPostRepairStatus::RESOLVED,
            $this->formatTime($now),
            $this->formatTime($now),
            $lease->getRepairKey(),
            DeletedPostRepairStatus::RUNNING,
            $lease->getToken(),
            $this->formatTime($now)
        );

        return 0 < $this->mutationOrFail($query, 'resolve deleted-post repair');
    }

    public function deleteTransientSuccess(DeletedPostRepairLease $lease): bool
    {
        $this->assertReady();

        global $wpdb;
        $query = $wpdb->prepare(
            "DELETE FROM `{$this->tableName}`
             WHERE `repair_key` = %s AND `status` = %s AND `lease_token` = %s AND `failure_count` = 0",
            $lease->getRepairKey(),
            DeletedPostRepairStatus::RUNNING,
            $lease->getToken()
        );

        return 0 < $this->mutationOrFail($query, 'delete transient successful repair');
    }

    public function recordWakeupFailure(
        string $repairKey,
        DeletedPostRepairDiagnostic $failure,
        DateTimeImmutable $now
    ): bool {
        $this->assertReady();
        $this->assertRepairKey($repairKey);
        $this->assertUtc($now);

        global $wpdb;
        $query = $wpdb->prepare(
            "UPDATE `{$this->tableName}` SET
                `wakeup_failure_category` = %s,
                `wakeup_failure_summary` = %s,
                `wakeup_failure_at` = %s,
                `updated_at` = %s
             WHERE `repair_key` = %s AND `status` <> %s AND `updated_at` <= %s
                AND (`status` <> %s OR `lease_expires_at` > %s)",
            $failure->getCategory(),
            $failure->getSummary(),
            $this->formatTime($now),
            $this->formatTime($now),
            $repairKey,
            DeletedPostRepairStatus::RESOLVED,
            $this->formatTime($now),
            DeletedPostRepairStatus::RUNNING,
            $this->formatTime($now)
        );

        return 0 < $this->mutationOrFail($query, 'record deleted-post repair wake-up failure');
    }

    public function findForClient(string $clientName, string $repairKey): ?DeletedPostRepairRecord
    {
        $this->assertReady();
        $this->assertClientName($clientName);
        $this->assertRepairKey($repairKey);

        global $wpdb;
        $query = $wpdb->prepare(
            "SELECT * FROM `{$this->tableName}` WHERE `client_name` = %s AND `repair_key` = %s LIMIT 1",
            $clientName,
            $repairKey
        );
        $records = $this->selectRecords($query, 'read deleted-post repair');

        return $records[0] ?? null;
    }

    /**
     * @return DeletedPostRepairRecord[]
     */
    public function listForClient(
        string $clientName,
        ?string $status = null,
        ?string $afterRepairKey = null,
        int $limit = 20
    ): array {
        $this->assertReady();
        $this->assertClientName($clientName);
        $limit = $this->assertLimit($limit);
        if (null !== $status) {
            DeletedPostRepairStatus::assertValid($status);
        }
        if (null !== $afterRepairKey) {
            $this->assertRepairKey($afterRepairKey);
        }

        global $wpdb;
        $where = [ '`client_name` = %s' ];
        $values = [ $clientName ];
        if (null !== $status) {
            $where[] = '`status` = %s';
            $values[] = $status;
        }
        if (null !== $afterRepairKey) {
            $where[] = '`repair_key` > %s';
            $values[] = $afterRepairKey;
        }
        $values[] = $limit;
        $query = $wpdb->prepare(
            "SELECT * FROM `{$this->tableName}` WHERE " . implode(' AND ', $where) .
            ' ORDER BY `repair_key` ASC LIMIT %d',
            ...$values
        );

        return $this->selectRecords($query, 'list deleted-post repairs for Client');
    }

    /**
     * @return DeletedPostRepairRecord[]
     */
    public function findDue(int $limit, DateTimeImmutable $now): array
    {
        $this->assertReady();
        $limit = $this->assertLimit($limit);
        $this->assertUtc($now);
        $timestamp = $this->formatTime($now);

        global $wpdb;
        $query = $wpdb->prepare(
            "SELECT * FROM `{$this->tableName}`
             WHERE `status` = %s
                OR (`status` = %s AND (`next_attempt_at` IS NULL OR `next_attempt_at` <= %s))
                OR (`status` = %s AND (`lease_expires_at` IS NULL OR `lease_expires_at` <= %s))
             ORDER BY `repair_key` ASC LIMIT %d",
            DeletedPostRepairStatus::ARMED,
            DeletedPostRepairStatus::RETRY_WAIT,
            $timestamp,
            DeletedPostRepairStatus::RUNNING,
            $timestamp,
            $limit
        );

        return $this->selectRecords($query, 'list due deleted-post repairs');
    }

    public function purgeResolvedBefore(DateTimeImmutable $cutoff, int $limit): int
    {
        $this->assertReady();
        $this->assertUtc($cutoff);
        $limit = $this->assertLimit($limit);
        $timestamp = $this->formatTime($cutoff);

        global $wpdb;
        $candidates = $this->selectRecords(
            $wpdb->prepare(
                "SELECT * FROM `{$this->tableName}`
             WHERE `status` = %s AND `failure_count` > 0 AND `resolved_at` < %s
             ORDER BY `repair_key` ASC LIMIT %d",
                DeletedPostRepairStatus::RESOLVED,
                $timestamp,
                $limit
            ),
            'validate resolved deleted-post repairs before purge'
        );
        if ([] === $candidates) {
            return 0;
        }

        $repairKeys = array_map(
            static fn(DeletedPostRepairRecord $record): string => $record->getIdentity()->getKey(),
            $candidates
        );
        $placeholders = implode(', ', array_fill(0, count($repairKeys), '%s'));
        $query = $wpdb->prepare(
            "DELETE FROM `{$this->tableName}`
             WHERE `repair_key` IN ({$placeholders})
                AND `status` = %s AND `failure_count` > 0 AND `resolved_at` < %s",
            ...[ ...$repairKeys, DeletedPostRepairStatus::RESOLVED, $timestamp ]
        );
        $affected = $this->mutationOrFail($query, 'purge resolved deleted-post repairs');
        if (count($repairKeys) < $affected) {
            throw $this->failure('purge resolved deleted-post repairs: unexpected affected-row count');
        }

        return $affected;
    }

    private function tryClaim(
        string $repairKey,
        string $storageFingerprint,
        DateTimeImmutable $now,
        DateTimeImmutable $leaseUntil,
        bool $manual
    ): DeletedPostRepairClaimResult {
        $record = $this->findByKey($repairKey);
        if (null === $record) {
            return new DeletedPostRepairClaimResult('not_found');
        }

        if (DeletedPostRepairStatus::RESOLVED === $record->getStatus()) {
            return new DeletedPostRepairClaimResult('resolved');
        }

        if ($record->getStorageFingerprint() !== $storageFingerprint) {
            return $this->adapterMismatchResult($repairKey, $storageFingerprint, $now);
        }

        $token = bin2hex(random_bytes(32));
        $timestamp = $this->formatTime($now);
        global $wpdb;

        if ($manual) {
            $claimable = "(`status` IN (%s, %s, %s) OR
                (`status` = %s AND `lease_expires_at` <= %s))";
            $conditionValues = [
                DeletedPostRepairStatus::ARMED,
                DeletedPostRepairStatus::RETRY_WAIT,
                DeletedPostRepairStatus::NEEDS_ATTENTION,
                DeletedPostRepairStatus::RUNNING,
                $timestamp,
            ];
        } else {
            $claimable = "(`status` = %s OR
                (`status` = %s AND `next_attempt_at` <= %s) OR
                (`status` = %s AND `lease_expires_at` <= %s))";
            $conditionValues = [
                DeletedPostRepairStatus::ARMED,
                DeletedPostRepairStatus::RETRY_WAIT,
                $timestamp,
                DeletedPostRepairStatus::RUNNING,
                $timestamp,
            ];
        }

        $values = [
            DeletedPostRepairStatus::RUNNING,
            $token,
            $this->formatTime($leaseUntil),
            $timestamp,
            $repairKey,
            $storageFingerprint,
            $timestamp,
            ...$conditionValues,
        ];
        $query = $wpdb->prepare(
            "UPDATE `{$this->tableName}` SET
                `status` = %s,
                `attempt_count` = `attempt_count` + 1,
                `next_attempt_at` = NULL,
                `lease_token` = %s,
                `lease_expires_at` = %s,
                `updated_at` = %s,
                `resolved_at` = NULL
             WHERE `repair_key` = %s AND `storage_fingerprint` = %s
                AND `updated_at` <= %s
                AND `attempt_count` < " . self::MAX_COUNTER . " AND {$claimable}",
            ...$values
        );
        $affected = $this->mutationOrFail($query, 'claim deleted-post repair lease');
        if (1 === $affected) {
            return new DeletedPostRepairClaimResult(
                'acquired',
                new DeletedPostRepairLease($repairKey, $token)
            );
        }
        if (1 < $affected) {
            throw $this->failure('claim deleted-post repair lease: unexpected affected-row count');
        }

        $current = $this->findByKey($repairKey);
        if (null === $current) {
            return new DeletedPostRepairClaimResult('not_found');
        }
        if (DeletedPostRepairStatus::RESOLVED === $current->getStatus()) {
            return new DeletedPostRepairClaimResult('resolved');
        }
        if ($current->getStorageFingerprint() !== $storageFingerprint) {
            return $this->adapterMismatchResult($repairKey, $storageFingerprint, $now);
        }
        if (
            DeletedPostRepairStatus::RUNNING === $current->getStatus() &&
            null !== $current->getLeaseExpiresAt() &&
            $current->getLeaseExpiresAt() > $now
        ) {
            return new DeletedPostRepairClaimResult('already_running');
        }
        if (
            ! $manual &&
            DeletedPostRepairStatus::RETRY_WAIT === $current->getStatus() &&
            null !== $current->getNextAttemptAt() &&
            $current->getNextAttemptAt() > $now
        ) {
            return new DeletedPostRepairClaimResult('not_due');
        }
        if (self::MAX_COUNTER === $current->getAttemptCount()) {
            throw $this->failure('claim deleted-post repair lease: attempt counter exhausted');
        }

        return new DeletedPostRepairClaimResult('unavailable');
    }

    private function failureTransitionResult(
        int $affected,
        DeletedPostRepairLease $lease,
        string $operation
    ): bool {
        if (1 === $affected) {
            return true;
        }
        if (1 < $affected) {
            throw $this->failure($operation . ': unexpected affected-row count');
        }

        $current = $this->findByKey($lease->getRepairKey());
        if (
            null !== $current &&
            DeletedPostRepairStatus::RUNNING === $current->getStatus() &&
            $lease->getToken() === $current->getLeaseToken() &&
            self::MAX_COUNTER === $current->getFailureCount()
        ) {
            throw $this->failure($operation . ': failure counter exhausted');
        }

        return false;
    }

    private function markAdapterMismatch(
        string $repairKey,
        string $runtimeFingerprint,
        DateTimeImmutable $now
    ): int {
        global $wpdb;
        $query = $wpdb->prepare(
            "UPDATE `{$this->tableName}` SET
                `status` = %s,
                `next_attempt_at` = NULL,
                `lease_token` = NULL,
                `lease_expires_at` = NULL,
                `updated_at` = %s,
                `resolved_at` = NULL
             WHERE `repair_key` = %s
                AND `storage_fingerprint` <> %s
                AND `updated_at` <= %s
                AND `status` <> %s
                AND (`status` <> %s OR `lease_expires_at` <= %s)",
            DeletedPostRepairStatus::NEEDS_ATTENTION,
            $this->formatTime($now),
            $repairKey,
            $runtimeFingerprint,
            $this->formatTime($now),
            DeletedPostRepairStatus::RESOLVED,
            DeletedPostRepairStatus::RUNNING,
            $this->formatTime($now)
        );
        return $this->mutationOrFail($query, 'record deleted-post repair adapter mismatch');
    }

    private function adapterMismatchResult(
        string $repairKey,
        string $runtimeFingerprint,
        DateTimeImmutable $now
    ): DeletedPostRepairClaimResult {
        $affected = $this->markAdapterMismatch($repairKey, $runtimeFingerprint, $now);
        if (1 === $affected) {
            return new DeletedPostRepairClaimResult('adapter_mismatch');
        }
        if (1 < $affected) {
            throw $this->failure('record deleted-post repair adapter mismatch: unexpected affected-row count');
        }

        $current = $this->findByKey($repairKey);
        if (null === $current) {
            return new DeletedPostRepairClaimResult('not_found');
        }
        if (DeletedPostRepairStatus::RESOLVED === $current->getStatus()) {
            return new DeletedPostRepairClaimResult('resolved');
        }
        if (
            DeletedPostRepairStatus::RUNNING === $current->getStatus() &&
            null !== $current->getLeaseExpiresAt() &&
            $current->getLeaseExpiresAt() > $now
        ) {
            return new DeletedPostRepairClaimResult('already_running');
        }
        if ($current->getUpdatedAt() > $now) {
            return new DeletedPostRepairClaimResult('unavailable');
        }
        if ($current->getStorageFingerprint() !== $runtimeFingerprint) {
            return new DeletedPostRepairClaimResult('adapter_mismatch');
        }

        return new DeletedPostRepairClaimResult('unavailable');
    }

    private function findByKey(string $repairKey): ?DeletedPostRepairRecord
    {
        global $wpdb;
        $query = $wpdb->prepare(
            "SELECT * FROM `{$this->tableName}` WHERE `repair_key` = %s LIMIT 1",
            $repairKey
        );
        $records = $this->selectRecords($query, 'read deleted-post repair claim state');

        return $records[0] ?? null;
    }

    /**
     * @return DeletedPostRepairRecord[]
     */
    private function selectRecords(string $query, string $operation): array
    {
        global $wpdb;
        $result = $wpdb->query($query);
        $databaseError = trim((string) $wpdb->last_error);
        if (false === $result) {
            throw $this->failure($operation, $databaseError);
        }
        if (! is_array($wpdb->last_result)) {
            throw $this->failure($operation . ': malformed result');
        }

        $records = [];
        try {
            foreach ($wpdb->last_result as $row) {
                if (! is_object($row)) {
                    throw new RuntimeException('Repair ledger row is not an object.');
                }
                $records[] = $this->hydrateRecord(get_object_vars($row));
            }
        } catch (Throwable $exception) {
            throw $this->failure($operation . ': malformed repair ledger row', '', $exception);
        }

        return $records;
    }

    private function hydrateRecord(array $row): DeletedPostRepairRecord
    {
        if (array_keys(self::COLUMNS) !== array_keys($row)) {
            throw new RuntimeException('Repair ledger row has an unexpected shape.');
        }

        $identity = new DeletedPostRepairIdentity(
            $this->rowPositiveInt($row, 'site_id'),
            $this->rowString($row, 'site_prefix'),
            $this->rowString($row, 'client_name'),
            $this->rowString($row, 'operation'),
            $this->rowPositiveInt($row, 'post_id')
        );
        if (
            $this->rowString($row, 'repair_key') !== $identity->getKey() ||
            $identity->getSiteId() !== $this->siteId ||
            $identity->getSitePrefix() !== $this->sitePrefix
        ) {
            throw new RuntimeException('Repair ledger identity does not match its site or key.');
        }

        $storageClass = $this->rowString($row, 'storage_class');
        $storageFingerprint = $this->rowString($row, 'storage_fingerprint');
        $storageLabel = new DeletedPostRepairDiagnostic('adapter', $storageClass, '', '');
        $status = $this->rowString($row, 'status');
        DeletedPostRepairStatus::assertValid($status);
        if (
            '' === $storageClass ||
            191 < strlen($storageClass) ||
            preg_match('/[\x00-\x1F\x7F]/', $storageClass) ||
            $storageClass !== $storageLabel->getClass() ||
            ! preg_match('/^[a-f0-9]{64}$/D', $storageFingerprint)
        ) {
            throw new RuntimeException('Repair ledger adapter identity is malformed.');
        }

        $attemptCount = $this->rowCounter($row, 'attempt_count');
        $failureCount = $this->rowCounter($row, 'failure_count');
        if ($failureCount > $attemptCount) {
            throw new RuntimeException('Repair ledger failure count exceeds its attempt count.');
        }

        $nextAttemptAt = $this->rowTime($row, 'next_attempt_at');
        $leaseToken = $this->rowNullableString($row, 'lease_token');
        if (null !== $leaseToken && ! preg_match('/^[a-f0-9]{64}$/D', $leaseToken)) {
            throw new RuntimeException('Repair ledger lease token is malformed.');
        }
        $leaseExpiresAt = $this->rowTime($row, 'lease_expires_at');

        $failureCategory = $this->rowNullableString($row, 'failure_category');
        $failureClass = $this->rowNullableString($row, 'failure_class');
        $failureCode = $this->rowNullableString($row, 'failure_code');
        $failureSummary = $this->rowNullableString($row, 'failure_summary');
        $failureValues = [ $failureCategory, $failureClass, $failureCode, $failureSummary ];
        if ($this->containsMixedNullability($failureValues)) {
            throw new RuntimeException('Repair ledger failure diagnostic is incomplete.');
        }
        $failureDiagnostic = null;
        if (null !== $failureCategory) {
            $failureDiagnostic = new DeletedPostRepairDiagnostic(
                $failureCategory,
                (string) $failureClass,
                (string) $failureCode,
                (string) $failureSummary
            );
            if (
                $failureCategory !== $failureDiagnostic->getCategory() ||
                $failureClass !== $failureDiagnostic->getClass() ||
                $failureCode !== $failureDiagnostic->getCode() ||
                $failureSummary !== $failureDiagnostic->getSummary()
            ) {
                throw new RuntimeException('Repair ledger failure diagnostic is not canonical.');
            }
        }
        $firstFailureAt = $this->rowTime($row, 'first_failure_at');
        $lastFailureAt = $this->rowTime($row, 'last_failure_at');

        $wakeupCategory = $this->rowNullableString($row, 'wakeup_failure_category');
        $wakeupSummary = $this->rowNullableString($row, 'wakeup_failure_summary');
        $wakeupFailureAt = $this->rowTime($row, 'wakeup_failure_at');
        if ($this->containsMixedNullability([ $wakeupCategory, $wakeupSummary, $wakeupFailureAt ])) {
            throw new RuntimeException('Repair ledger wake-up diagnostic is incomplete.');
        }
        $wakeupDiagnostic = null;
        if (null !== $wakeupCategory) {
            $wakeupDiagnostic = new DeletedPostRepairDiagnostic(
                $wakeupCategory,
                '',
                '',
                (string) $wakeupSummary
            );
            if (
                $wakeupCategory !== $wakeupDiagnostic->getCategory() ||
                $wakeupSummary !== $wakeupDiagnostic->getSummary()
            ) {
                throw new RuntimeException('Repair ledger wake-up diagnostic is not canonical.');
            }
        }

        $createdAt = $this->requiredRowTime($row, 'created_at');
        $updatedAt = $this->requiredRowTime($row, 'updated_at');
        $resolvedAt = $this->rowTime($row, 'resolved_at');
        $this->assertRecordState(
            $status,
            $attemptCount,
            $failureCount,
            $nextAttemptAt,
            $leaseToken,
            $leaseExpiresAt,
            $failureDiagnostic,
            $firstFailureAt,
            $lastFailureAt,
            $wakeupFailureAt,
            $resolvedAt,
            $createdAt,
            $updatedAt
        );

        return new DeletedPostRepairRecord(
            $identity,
            $storageClass,
            $storageFingerprint,
            $status,
            $attemptCount,
            $failureCount,
            $nextAttemptAt,
            $leaseToken,
            $leaseExpiresAt,
            $failureDiagnostic,
            $firstFailureAt,
            $lastFailureAt,
            $wakeupDiagnostic,
            $wakeupFailureAt,
            $createdAt,
            $updatedAt,
            $resolvedAt
        );
    }

    /**
     * @param array<int, mixed> $values
     */
    private function containsMixedNullability(array $values): bool
    {
        $nulls = count(array_filter($values, static fn($value): bool => null === $value));

        return 0 < $nulls && count($values) !== $nulls;
    }

    private function assertRecordState(
        string $status,
        int $attemptCount,
        int $failureCount,
        ?DateTimeImmutable $nextAttemptAt,
        ?string $leaseToken,
        ?DateTimeImmutable $leaseExpiresAt,
        ?DeletedPostRepairDiagnostic $failureDiagnostic,
        ?DateTimeImmutable $firstFailureAt,
        ?DateTimeImmutable $lastFailureAt,
        ?DateTimeImmutable $wakeupFailureAt,
        ?DateTimeImmutable $resolvedAt,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt
    ): void {
        if ((null === $leaseToken) !== (null === $leaseExpiresAt)) {
            throw new RuntimeException('Repair ledger lease state is incomplete.');
        }
        if (0 === $failureCount) {
            if (null !== $failureDiagnostic || null !== $firstFailureAt || null !== $lastFailureAt) {
                throw new RuntimeException('Repair ledger zero-failure state contains failure details.');
            }
        } elseif (null === $failureDiagnostic || null === $firstFailureAt || null === $lastFailureAt) {
            throw new RuntimeException('Repair ledger failed state lacks failure details.');
        }
        if (null !== $firstFailureAt && null !== $lastFailureAt && $firstFailureAt > $lastFailureAt) {
            throw new RuntimeException('Repair ledger failure timestamps are out of order.');
        }
        if ($createdAt > $updatedAt) {
            throw new RuntimeException('Repair ledger timestamps are out of order.');
        }
        if (
            (null !== $firstFailureAt && $firstFailureAt < $createdAt) ||
            (null !== $lastFailureAt && $lastFailureAt > $updatedAt) ||
            (null !== $wakeupFailureAt && ($wakeupFailureAt < $createdAt || $wakeupFailureAt > $updatedAt)) ||
            (null !== $resolvedAt && $resolvedAt > $updatedAt)
        ) {
            throw new RuntimeException('Repair ledger event timestamp is outside the record lifetime.');
        }

        $hasLease = null !== $leaseToken;
        if (DeletedPostRepairStatus::ARMED === $status) {
            if (0 !== $attemptCount || 0 !== $failureCount || $hasLease || null !== $nextAttemptAt || null !== $resolvedAt) {
                throw new RuntimeException('Repair ledger armed state is malformed.');
            }
            return;
        }
        if (DeletedPostRepairStatus::RUNNING === $status) {
            if (
                1 > $attemptCount ||
                ! $hasLease ||
                null === $leaseExpiresAt ||
                $leaseExpiresAt <= $updatedAt ||
                null !== $nextAttemptAt ||
                null !== $resolvedAt
            ) {
                throw new RuntimeException('Repair ledger running state is malformed.');
            }
            return;
        }
        if (DeletedPostRepairStatus::RETRY_WAIT === $status) {
            if (
                1 > $attemptCount ||
                1 > $failureCount ||
                $hasLease ||
                null === $nextAttemptAt ||
                null === $lastFailureAt ||
                $nextAttemptAt < $lastFailureAt ||
                null !== $resolvedAt
            ) {
                throw new RuntimeException('Repair ledger retry-wait state is malformed.');
            }
            return;
        }
        if (DeletedPostRepairStatus::NEEDS_ATTENTION === $status) {
            if ($hasLease || null !== $nextAttemptAt || null !== $resolvedAt) {
                throw new RuntimeException('Repair ledger attention state is malformed.');
            }
            return;
        }
        if (
            1 > $attemptCount ||
            1 > $failureCount ||
            $hasLease ||
            null !== $nextAttemptAt ||
            null === $resolvedAt
        ) {
            throw new RuntimeException('Repair ledger resolved state is malformed.');
        }
    }

    private function createTable(): void
    {
        global $wpdb;
        $charsetCollate = trim((string) $wpdb->get_charset_collate());
        $query = "CREATE TABLE `{$this->tableName}` (
            `repair_key` char(64) NOT NULL,
            `site_id` bigint unsigned NOT NULL,
            `site_prefix` varchar(64) NOT NULL,
            `client_name` varchar(191) NOT NULL,
            `storage_class` varchar(255) NOT NULL,
            `storage_fingerprint` char(64) NOT NULL,
            `operation` varchar(64) NOT NULL,
            `post_id` bigint unsigned NOT NULL,
            `status` varchar(32) NOT NULL,
            `attempt_count` int unsigned NOT NULL DEFAULT 0,
            `failure_count` int unsigned NOT NULL DEFAULT 0,
            `next_attempt_at` datetime NULL,
            `lease_token` char(64) NULL,
            `lease_expires_at` datetime NULL,
            `failure_category` varchar(64) NULL,
            `failure_class` varchar(255) NULL,
            `failure_code` varchar(64) NULL,
            `failure_summary` longtext NULL,
            `first_failure_at` datetime NULL,
            `last_failure_at` datetime NULL,
            `wakeup_failure_category` varchar(64) NULL,
            `wakeup_failure_summary` longtext NULL,
            `wakeup_failure_at` datetime NULL,
            `created_at` datetime NOT NULL,
            `updated_at` datetime NOT NULL,
            `resolved_at` datetime NULL,
            PRIMARY KEY (`repair_key`),
            KEY `status_due` (`status`, `next_attempt_at`),
            KEY `status_lease` (`status`, `lease_expires_at`),
            KEY `client_status` (`client_name`, `status`),
            KEY `status_resolved` (`status`, `resolved_at`)
        ) ENGINE=InnoDB {$charsetCollate}";

        $suppress = $wpdb->suppress_errors();
        try {
            $result = $wpdb->query($query);
            $databaseError = trim((string) $wpdb->last_error);
        } finally {
            $wpdb->suppress_errors($suppress);
        }

        if (false === $result && ! $this->tableExists()) {
            throw $this->failure('install repair ledger schema', $databaseError);
        }
    }

    private function assertSchemaReady(): void
    {
        $issues = $this->schemaIssues();
        if ([] !== $issues) {
            throw $this->failure('verify repair ledger schema for InnoDB: ' . implode('; ', $issues));
        }
    }

    /**
     * @return string[]
     */
    private function schemaIssues(): array
    {
        global $wpdb;
        if (! $this->tableExists()) {
            return [ 'table is missing' ];
        }

        $issues = [];
        $columns = [];
        $columnRows = $wpdb->get_results("SHOW FULL COLUMNS FROM `{$this->tableName}`", ARRAY_A);
        if (! is_array($columnRows) || '' !== trim((string) $wpdb->last_error)) {
            throw $this->failure('verify repair ledger schema columns');
        }
        foreach ($columnRows as $column) {
            $columns[(string) $column['Field']] = [
                'type' => $this->normalizeColumnType((string) $column['Type']),
                'nullable' => 'YES' === ($column['Null'] ?? ''),
                'default' => $column['Default'] ?? null,
                'extra' => $this->normalizeColumnExtra((string) ($column['Extra'] ?? '')),
            ];
        }
        if (self::COLUMNS !== $columns) {
            $issues[] = 'columns are missing or incompatible';
        }

        if (self::INDEXES !== $this->readIndexes()) {
            $issues[] = 'indexes are missing or incompatible';
        }

        $definition = $wpdb->get_row("SHOW CREATE TABLE `{$this->tableName}`", ARRAY_N);
        if (! is_array($definition) || '' !== trim((string) $wpdb->last_error)) {
            throw $this->failure('verify repair ledger schema definition');
        }
        $engine = is_array($definition) && isset($definition[1]) &&
            preg_match('/\bENGINE=([A-Za-z0-9_]+)/i', $definition[1], $matches)
            ? strtoupper($matches[1])
            : '';
        if (self::REQUIRED_ENGINE !== $engine) {
            $issues[] = '' === $engine ? 'InnoDB engine cannot be verified' : "InnoDB required; found {$engine}";
        }
        if (isset($definition[1]) && preg_match('/\b(?:FOREIGN\s+KEY|CHECK\s*\()/i', (string) $definition[1])) {
            $issues[] = 'foreign-key or check constraints are not supported';
        }

        return $issues;
    }

    private function readIndexes(): array
    {
        global $wpdb;
        $indexes = [];
        $rows = $wpdb->get_results("SHOW INDEX FROM `{$this->tableName}`", ARRAY_A);
        if (! is_array($rows) || '' !== trim((string) $wpdb->last_error)) {
            throw $this->failure('verify repair ledger schema indexes');
        }
        foreach ($rows as $row) {
            $name = (string) $row['Key_name'];
            if (! isset($indexes[$name])) {
                $indexes[$name] = [
                    'non_unique' => (int) $row['Non_unique'],
                    'type' => strtoupper((string) $row['Index_type']),
                    'usable' => (! isset($row['Visible']) || 'YES' === strtoupper((string) $row['Visible'])) &&
                        (! isset($row['Ignored']) || 'NO' === strtoupper((string) $row['Ignored'])),
                    'columns' => [],
                ];
            }
            $indexes[$name]['columns'][(int) $row['Seq_in_index']] = [
                'name' => (string) $row['Column_name'],
                'prefix' => null === ($row['Sub_part'] ?? null) ? null : (int) $row['Sub_part'],
            ];
        }
        foreach ($indexes as &$index) {
            ksort($index['columns'], SORT_NUMERIC);
            $index['columns'] = array_values($index['columns']);
        }
        unset($index);
        ksort($indexes, SORT_STRING);

        return $indexes;
    }

    private function tableExists(): bool
    {
        global $wpdb;
        $suppress = $wpdb->suppress_errors();
        try {
            $query = $wpdb->prepare(
                'SELECT `TABLE_NAME` FROM `information_schema`.`TABLES` '
                . 'WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = %s LIMIT 1',
                $this->tableName
            );
            $found = $wpdb->get_var($query);
            $databaseError = trim((string) $wpdb->last_error);
        } finally {
            $wpdb->suppress_errors($suppress);
        }

        if ('' !== $databaseError) {
            throw $this->failure('verify repair ledger schema existence');
        }
        if (null === $found) {
            return false;
        }
        if ($this->tableName !== $found) {
            throw $this->failure('verify repair ledger schema existence: unexpected result');
        }

        return true;
    }

    private function claimOwnership(): void
    {
        $record = $this->expectedOwnership();
        global $wpdb;
        $suppress = $wpdb->suppress_errors();
        try {
            $added = add_option(self::OWNERSHIP_OPTION, $record, '', false);
        } finally {
            $wpdb->suppress_errors($suppress);
        }
        if (! $added) {
            wp_cache_delete(self::OWNERSHIP_OPTION, 'options');
            wp_cache_delete('notoptions', 'options');
        }
        $persisted = $this->readOwnershipRecord();
        if (null === $persisted) {
            throw $this->failure('claim repair ledger ownership: ambiguous concurrent result');
        }
        $this->assertOwnership($persisted);
    }

    /**
     * @return array{record: mixed, autoload: string}|null
     */
    private function readOwnershipRecord(): ?array
    {
        global $wpdb;
        $suppress = $wpdb->suppress_errors();
        try {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT `option_value`, `autoload` FROM `{$this->optionsTable}` "
                    . "WHERE `option_name` = %s LIMIT 1",
                    self::OWNERSHIP_OPTION
                ),
                ARRAY_A
            );
            $databaseError = trim((string) $wpdb->last_error);
        } finally {
            $wpdb->suppress_errors($suppress);
        }
        if ('' !== $databaseError) {
            throw $this->failure('read repair ledger ownership');
        }
        if (null === $row) {
            return null;
        }
        if (
            [ 'option_value', 'autoload' ] !== array_keys($row) ||
            ! is_string($row['option_value']) ||
            ! is_string($row['autoload'])
        ) {
            throw $this->failure('read repair ledger ownership: malformed result');
        }

        return [
            'record' => maybe_unserialize($row['option_value']),
            'autoload' => $row['autoload'],
        ];
    }

    /**
     * @param array{record: mixed, autoload: string} $ownership
     */
    private function assertOwnership(array $ownership): void
    {
        if ($this->expectedOwnership() !== $ownership['record']) {
            throw $this->failure('verify repair ledger ownership: record is malformed or incompatible');
        }

        $autoloadValues = function_exists('wp_autoload_values_to_autoload')
            ? wp_autoload_values_to_autoload()
            : [ 'yes', 'on', 'auto-on', 'auto' ];
        if (in_array($ownership['autoload'], $autoloadValues, true)) {
            throw $this->failure('verify repair ledger ownership: record must not autoload');
        }
    }

    private function expectedOwnership(): array
    {
        return [
            'version' => self::SCHEMA_VERSION,
            'table' => self::TABLE_KEY,
            'owner' => self::OWNER,
        ];
    }

    private function registerTable(): void
    {
        global $wpdb;
        if (! in_array(self::TABLE_KEY, $wpdb->tables, true)) {
            $wpdb->tables[] = self::TABLE_KEY;
        }
        $wpdb->{self::TABLE_KEY} = $this->tableName;
    }

    private function assertContextAndIdentifier(): void
    {
        global $wpdb;
        if (
            $wpdb !== $this->database ||
            $this->sitePrefix !== (string) $wpdb->prefix ||
            $this->siteId !== (int) get_current_blog_id() ||
            $this->optionsTable !== (string) $wpdb->options
        ) {
            throw $this->failure('repair ledger context changed after construction');
        }
        if (
            0 >= $this->siteId ||
            '' === $this->sitePrefix ||
            ! preg_match('/^[A-Za-z0-9_]+$/D', $this->sitePrefix) ||
            ! preg_match('/^[A-Za-z0-9_]+$/D', $this->tableName) ||
            ! preg_match('/^[A-Za-z0-9_]+$/D', $this->optionsTable) ||
            $this->sitePrefix . 'options' !== $this->optionsTable ||
            64 < strlen($this->tableName) ||
            64 < strlen($this->optionsTable)
        ) {
            throw $this->failure('repair ledger table identifier is unsafe or exceeds the 64-character database limit');
        }
        if (isset($wpdb->{self::TABLE_KEY}) && $this->tableName !== $wpdb->{self::TABLE_KEY}) {
            throw $this->failure('repair ledger context has a conflicting WordPress database table mapping');
        }
    }

    private function assertIdentityContext(DeletedPostRepairIdentity $identity): void
    {
        if (
            $identity->getSiteId() !== $this->siteId ||
            $identity->getSitePrefix() !== $this->sitePrefix
        ) {
            throw $this->failure('repair ledger identity belongs to a different site context');
        }
    }

    private function assertClientName(string $clientName): void
    {
        if (
            '' === $clientName ||
            191 < strlen($clientName) ||
            ! preg_match('/^[a-z0-9_-]+$/D', $clientName)
        ) {
            throw new InvalidArgumentException('Repair Client name is not canonical.');
        }
    }

    private function assertRepairKey(string $repairKey): void
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $repairKey)) {
            throw new InvalidArgumentException('Invalid deleted-post repair key.');
        }
    }

    private function assertLimit(int $limit): int
    {
        if (0 >= $limit || self::MAX_PAGE_SIZE < $limit) {
            throw new InvalidArgumentException('Repair query limit must be between 1 and 100.');
        }

        return $limit;
    }

    private function assertLeaseWindow(DateTimeImmutable $now, DateTimeImmutable $leaseUntil): void
    {
        $this->assertUtc($now);
        $this->assertUtc($leaseUntil);
        if ($this->formatTime($leaseUntil) <= $this->formatTime($now)) {
            throw new InvalidArgumentException('Repair lease must expire after its claim time.');
        }
    }

    private function assertUtc(DateTimeImmutable $time): void
    {
        if (0 !== $time->getOffset()) {
            throw new InvalidArgumentException('Repair ledger timestamps must be UTC.');
        }
        $year = (int) $time->format('Y');
        if (1000 > $year || 9999 < $year) {
            throw new InvalidArgumentException('Repair ledger timestamps must fit the database datetime range.');
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function storageIdentity(object $storage): array
    {
        $class = get_class($storage);
        $diagnostic = new DeletedPostRepairDiagnostic('adapter', $class, '', '');
        $label = $diagnostic->getClass();
        if ('' === $label) {
            $label = 'anonymous-adapter';
        }

        return [ $label, hash('sha256', $class) ];
    }

    private function formatTime(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function rowString(array $row, string $key): string
    {
        if (! array_key_exists($key, $row) || ! is_string($row[$key])) {
            throw new RuntimeException("Repair ledger field {$key} is not a string.");
        }

        return $row[$key];
    }

    private function rowNullableString(array $row, string $key): ?string
    {
        if (! array_key_exists($key, $row) || (null !== $row[$key] && ! is_string($row[$key]))) {
            throw new RuntimeException("Repair ledger field {$key} is malformed.");
        }

        return $row[$key];
    }

    private function rowUnsignedInt(array $row, string $key): int
    {
        $value = $this->rowString($row, $key);
        $maximum = (string) PHP_INT_MAX;
        if (
            ! preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) ||
            strlen($value) > strlen($maximum) ||
            (strlen($value) === strlen($maximum) && 0 < strcmp($value, $maximum))
        ) {
            throw new RuntimeException("Repair ledger field {$key} is not an unsigned integer.");
        }

        return (int) $value;
    }

    private function rowCounter(array $row, string $key): int
    {
        $value = $this->rowUnsignedInt($row, $key);
        if (self::MAX_COUNTER < $value) {
            throw new RuntimeException("Repair ledger field {$key} exceeds its counter range.");
        }

        return $value;
    }

    private function rowPositiveInt(array $row, string $key): int
    {
        $value = $this->rowUnsignedInt($row, $key);
        if (0 >= $value) {
            throw new RuntimeException("Repair ledger field {$key} is not positive.");
        }

        return $value;
    }

    private function rowTime(array $row, string $key): ?DateTimeImmutable
    {
        $value = $this->rowNullableString($row, $key);
        if (null === $value) {
            return null;
        }

        $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (false === $time || (false !== $errors && (0 < $errors['warning_count'] || 0 < $errors['error_count']))) {
            throw new RuntimeException("Repair ledger field {$key} is not a UTC database timestamp.");
        }

        return $time;
    }

    private function requiredRowTime(array $row, string $key): DateTimeImmutable
    {
        $time = $this->rowTime($row, $key);
        if (null === $time) {
            throw new RuntimeException("Repair ledger field {$key} is required.");
        }

        return $time;
    }

    private function normalizeColumnType(string $type): string
    {
        $type = strtolower(trim((string) preg_replace('/\s+/', ' ', $type)));

        return (string) preg_replace(
            '/\b(tinyint|smallint|mediumint|int|integer|bigint)\([0-9]+\)/',
            '$1',
            $type
        );
    }

    private function normalizeColumnExtra(string $extra): string
    {
        $extra = strtolower(trim($extra));

        return 'null' === $extra ? '' : $extra;
    }

    private function mutationOrFail(string $query, string $operation): int
    {
        global $wpdb;
        $result = $wpdb->query($query);
        if (false === $result) {
            throw $this->failure($operation, trim((string) $wpdb->last_error));
        }

        return (int) $result;
    }

    private function failure(
        string $operation,
        string $databaseError = '',
        ?Throwable $previous = null
    ): StorageFailure {
        if ('' !== $databaseError) {
            $previous = new RuntimeException($databaseError, 0, $previous);
        }

        return new StorageFailure($operation, $previous);
    }
}
