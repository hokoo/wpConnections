<?php

namespace iTRON\wpConnections\Abstracts;

class Relation implements IArrayConvertable
{
    public string $name = '';
    public string $from;
    public string $to;

    /**
     * 'from', 'to', 'both'
     */
    public string $type;

    /**
     * '1-1', '1-m', 'm-m', 'm-1'
     */
    public string $cardinality;

    /**
     * Ability to make identical connections
     */
    public bool $duplicatable;

    /**
     * Ability to make self-connections
     */
    public bool $closurable;

    public function toArray(): array
    {
        return [
            'name'          => $this->name,
            'from'          => $this->from,
            'to'            => $this->to,
            'type'          => $this->type,
            'cardinality'   => $this->cardinality,
            'duplicatable'  => $this->duplicatable,
            'closurable'    => $this->closurable,
        ];
    }
}
