<?php

namespace iTRON\wpConnections\Exceptions;

use Throwable;

class RelationNotFound extends Exception
{
    public string $relation = '';

    public function __construct(\Throwable $previous = null)
    {
        parent::__construct('Relation not found: ', 1, $previous);
    }

    public function setRelation(string $relation): self
    {
        $this->relation = $relation;
        $this->setMessage($this->relation);

        return $this;
    }

    public function getRelation(): string
    {
        return $this->relation;
    }
}
