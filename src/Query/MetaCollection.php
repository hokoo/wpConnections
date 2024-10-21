<?php

namespace iTRON\wpConnections\Query;

use iTRON\wpConnections\GSInterface;

class MetaCollection extends \iTRON\wpConnections\MetaCollection
{
    use GSInterface;

    public string $collectionType = Meta::class;

    public function __construct()
    {
        parent::__construct();
    }

    public function where(string $propertyOrMethod, $value): self
    {
        /** @var MetaCollection $result */
        $result = parent::where($propertyOrMethod, $value);
        return $result;
    }
}
