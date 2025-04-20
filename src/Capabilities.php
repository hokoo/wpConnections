<?php

namespace iTRON\wpConnections;

class Capabilities
{
    public const DEFAULT = 'manage_options';

    private array $capabilities = [];

    public function __construct(private string $defaultCap = '')
    {
        $this->defaultCap = $defaultCap ?: self::DEFAULT;
    }

    public function __get(string $capability): string
    {
        return $this->capabilities[ $capability ] ?? $this->defaultCap;
    }

    public function __set(string $capability, string $value): void
    {
        $this->capabilities[ $capability ] = $value;
    }
}
