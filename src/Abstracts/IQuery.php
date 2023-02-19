<?php

namespace iTRON\wpConnections\Abstracts;

interface IQuery extends IGetSet {

    /**
     * TRUE means PATCH-like updating, FALSE means PUT-like updating.
     *
     * @return bool
     */
    public function isUpdate(): bool;
    public function setIsUpdate( bool $isUpdate );
}
