<?php

namespace iTRON\wpConnections;

class Capabilities
{
    private array $capabilities = [];
    private string $defaultCap = '';

    public function __construct(string $defaultCap = '')
    {
        $this->defaultCap = $defaultCap ?: 'manage_options';
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
