<?php

namespace iTRON\wpConnections;

trait IQueryTrait
{
    protected bool $isUpdate = true;

    public function isUpdate(): bool
    {
        return $this->isUpdate;
    }

    public function setIsUpdate(bool $isUpdate)
    {
        $this->isUpdate = $isUpdate;
    }
}
