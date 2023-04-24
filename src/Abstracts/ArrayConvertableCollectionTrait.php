<?php

namespace iTRON\wpConnections\Abstracts;

trait ArrayConvertableCollectionTrait
{
    public function toArray(): array
    {
        return array_map(
            function (IArrayConvertable $element) {
                return $element->toArray();
            },
            $this->data
        );
    }
}
