<?php

namespace iTRON\wpConnections;

class Capabilities
{
    public const DEFAULT = 'manage_options';

    private array $capabilities = [];

    public function __get(string $capability): string
    {
        return $this->capabilities[ $capability ] ?? self::DEFAULT;
    }

    public function __set(string $capability, string $value): void
    {
        $this->capabilities[ $capability ] = $value;
    }
}
