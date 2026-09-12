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
    private array $providedValues = [];
    private array $materializedValues = [];

    public function __construct($from = 0, $to = 0, $both = 0)
    {
        parent::__construct();
        unset($this->id, $this->from, $this->to, $this->both);

        $providedArguments = func_num_args();
        if (0 < $providedArguments) {
            $this->setPresenceTrackedValue('from', $from);
        }
        if (1 < $providedArguments) {
            $this->setPresenceTrackedValue('to', $to);
        }
        if (2 < $providedArguments) {
            $this->setPresenceTrackedValue('both', $both);
        }
    }

    public function set(string $field, $value): self
    {
        if ($this->isPresenceTrackedField($field)) {
            $this->setPresenceTrackedValue($field, $value);

            return $this;
        }

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
        if (
            $this->isPresenceTrackedField($field) &&
            array_key_exists($field, $this->providedValues)
        ) {
            return $this->providedValues[ $field ];
        }

        if ($this->isOmittedPresenceTrackedField($field)) {
            return 0;
        }

        return $this->getMagicValue($field);
    }

    public function __set($field, $value): void
    {
        if ($this->isPresenceTrackedField($field)) {
            $this->setPresenceTrackedValue($field, $value);

            return;
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

    /**
     * Returns the exact value supplied before PHP coerces a typed public
     * property. Selector dispatch must choose a field before normalizing it.
     *
     * @internal
     */
    public function getProvidedValue(string $field)
    {
        if (array_key_exists($field, $this->providedValues)) {
            $property = new \ReflectionProperty($this, $field);
            if (
                $property->isInitialized($this) &&
                array_key_exists($field, $this->materializedValues) &&
                $this->{$field} !== $this->materializedValues[ $field ]
            ) {
                return $this->{$field};
            }

            return $this->providedValues[ $field ];
        }

        return $this->get($field);
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

    private function setPresenceTrackedValue(string $field, $value): void
    {
        $this->providedFields[ $field ] = true;
        $this->providedValues[ $field ] = $value;

        if (is_float($value)) {
            unset($this->{$field}, $this->materializedValues[ $field ]);
            return;
        }

        try {
            $this->{$field} = $value;
            $this->materializedValues[ $field ] = $this->{$field};
        } catch (\TypeError) {
            unset($this->{$field});
            unset($this->materializedValues[ $field ]);
        }
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
