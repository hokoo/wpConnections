<?php

namespace iTRON\wpConnections\Query;

use iTRON\wpConnections\GSInterface;

class Connection extends \iTRON\wpConnections\Abstracts\Connection
{
    use GSInterface {
        set as private setValue;
        get as private getValue;
        __get as private getMagicValue;
    }

    public int $both = 0;
    private array $providedFields = [];

    public function __construct(int $from = 0, int $to = 0, int $both = 0)
    {
        parent::__construct();
        unset($this->id);

        $providedArguments = func_num_args();
        if (0 < $providedArguments) {
            $this->from = $from;
            $this->providedFields['from'] = true;
        } else {
            unset($this->from);
        }
        if (1 < $providedArguments) {
            $this->to = $to;
            $this->providedFields['to'] = true;
        } else {
            unset($this->to);
        }
        if (2 < $providedArguments) {
            $this->both = $both;
            $this->providedFields['both'] = true;
        } else {
            unset($this->both);
        }
    }

    public function set(string $field, $value): self
    {
        $this->providedFields[ $field ] = true;

        return $this->setValue($field, $value);
    }

    public function get(string $field)
    {
        if ($this->isOmittedPresenceTrackedField($field)) {
            return 0;
        }

        return $this->getValue($field);
    }

    public function __get($field)
    {
        if ($this->isOmittedPresenceTrackedField($field)) {
            return 0;
        }

        return $this->getMagicValue($field);
    }

    public function __set($field, $value): void
    {
        if ($this->isPresenceTrackedField($field)) {
            $this->providedFields[ $field ] = true;
        }

        $this->{$field} = $value;
    }

    public function __isset($field): bool
    {
        return $this->isPresenceTrackedField($field);
    }

    public function isProvided(string $field): bool
    {
        if (isset($this->providedFields[ $field ])) {
            return true;
        }

        if ($this->isPresenceTrackedField($field)) {
            return false;
        }

        if (! property_exists($this, $field)) {
            return false;
        }

        $property = new \ReflectionProperty($this, $field);

        return $property->isInitialized($this);
    }

    private function isPresenceTrackedField(string $field): bool
    {
        return in_array($field, [ 'id', 'from', 'to', 'both' ], true);
    }

    private function isOmittedPresenceTrackedField(string $field): bool
    {
        if (! $this->isPresenceTrackedField($field)) {
            return false;
        }

        $property = new \ReflectionProperty($this, $field);

        return ! $property->isInitialized($this);
    }

    public function exists_relation(): bool
    {
        return ! empty($this->relation);
    }

    public function exists_from(): bool
    {
        return $this->from > 0;
    }

    public function exists_to(): bool
    {
        return $this->to > 0;
    }

    public function exists_both(): bool
    {
        return $this->both > 0;
    }

    protected function getMetaCollection(): MetaCollection
    {
        return new MetaCollection();
    }
}
