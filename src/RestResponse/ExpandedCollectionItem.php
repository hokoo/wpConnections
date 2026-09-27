<?php

namespace iTRON\wpConnections\RestResponse;

/** Keeps the v1 item fields and links while adding projected entities. */
class ExpandedCollectionItem extends CollectionItem
{
    public array $entities = [];
}
