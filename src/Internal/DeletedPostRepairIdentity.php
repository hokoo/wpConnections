<?php

namespace iTRON\wpConnections\Internal;

use InvalidArgumentException;

final class DeletedPostRepairIdentity
{
    private int $siteId;
    private string $sitePrefix;
    private string $clientName;
    private string $operation;
    private int $postId;
    private string $key;

    public function __construct(
        int $siteId,
        string $sitePrefix,
        string $clientName,
        string $operation,
        int $postId
    ) {
        if (0 >= $siteId) {
            throw new InvalidArgumentException('Repair site ID must be positive.');
        }

        if ('' === $sitePrefix || 64 < strlen($sitePrefix) || ! preg_match('/^[A-Za-z0-9_]+$/D', $sitePrefix)) {
            throw new InvalidArgumentException('Repair site prefix is not canonical.');
        }

        if (
            '' === $clientName ||
            191 < strlen($clientName) ||
            ! preg_match('/^[a-z0-9_-]+$/D', $clientName)
        ) {
            throw new InvalidArgumentException('Repair Client name is not canonical.');
        }

        if (
            '' === $operation ||
            64 < strlen($operation) ||
            ! preg_match('/^[a-z0-9_]+:v[1-9][0-9]*$/D', $operation)
        ) {
            throw new InvalidArgumentException('Repair operation is not canonical or versioned.');
        }

        if (0 >= $postId) {
            throw new InvalidArgumentException('Repair post ID must be positive.');
        }

        $this->siteId = $siteId;
        $this->sitePrefix = $sitePrefix;
        $this->clientName = $clientName;
        $this->operation = $operation;
        $this->postId = $postId;
        $this->key = $this->makeKey();
    }

    public function getSiteId(): int
    {
        return $this->siteId;
    }

    public function getSitePrefix(): string
    {
        return $this->sitePrefix;
    }

    public function getClientName(): string
    {
        return $this->clientName;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function getPostId(): int
    {
        return $this->postId;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    private function makeKey(): string
    {
        $payload = '';
        foreach (
            [
                (string) $this->siteId,
                $this->sitePrefix,
                $this->clientName,
                $this->operation,
                (string) $this->postId,
            ] as $value
        ) {
            $payload .= pack('N', strlen($value)) . $value;
        }

        return hash('sha256', $payload);
    }
}
