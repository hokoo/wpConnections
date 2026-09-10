<?php

namespace iTRON\wpConnections\Query;

use iTRON\wpConnections\GSInterface;

class Connection extends \iTRON\wpConnections\Abstracts\Connection
{
    use GSInterface {
        set as private setValue;
    }

    public int $both = 0;
    private array $providedFields = [];

    public function __construct(int $from = 0, int $to = 0, int $both = 0)
    {
        parent::__construct();
        $this->from = $from;
        $this->to = $to;
        $this->both = $both;

        $providedArguments = func_num_args();
        if (0 < $providedArguments) {
            $this->providedFields['from'] = true;
        }
        if (1 < $providedArguments) {
            $this->providedFields['to'] = true;
        }
        if (2 < $providedArguments) {
            $this->providedFields['both'] = true;
        }
    }

    public function set(string $field, $value): self
    {
        $this->providedFields[ $field ] = true;

        return $this->setValue($field, $value);
    }

    public function isProvided(string $field): bool
    {
        if (isset($this->providedFields[ $field ])) {
            return true;
        }

        if (in_array($field, [ 'from', 'to', 'both' ], true)) {
            // The legacy public properties are initialized to zero. A direct
            // write of that same value is indistinguishable from no write;
            // callers that need sparse presence semantics use set() or the
            // constructor, both of which are tracked above.
            return 0 !== $this->get($field);
        }

        if (! property_exists($this, $field)) {
            return false;
        }

        $property = new \ReflectionProperty($this, $field);

        return $property->isInitialized($this);
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
