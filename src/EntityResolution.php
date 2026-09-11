<?php

namespace iTRON\wpConnections;

final class EntityResolution
{
    public const ACCEPTED = 'accepted';
    public const MISSING = 'missing';
    public const WRONG_TYPE = 'wrong_type';
    public const FAILED = 'failed';

    private string $status;
    private ?string $actualEntityType;

    private function __construct(string $status, ?string $actualEntityType = null)
    {
        $this->status = $status;
        $this->actualEntityType = $actualEntityType;
    }

    public static function accepted(): self
    {
        return new self(self::ACCEPTED);
    }

    public static function missing(): self
    {
        return new self(self::MISSING);
    }

    public static function wrongType(string $actualEntityType): self
    {
        return new self(self::WRONG_TYPE, $actualEntityType);
    }

    public static function failed(): self
    {
        return new self(self::FAILED);
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getActualEntityType(): ?string
    {
        return $this->actualEntityType;
    }
}
