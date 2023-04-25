<?php

namespace iTRON\wpConnections;

use iTRON\wpConnections\Abstracts\IArrayConvertable;
use Ramsey\Collection\Collection;

class MetaCollection extends Collection implements IArrayConvertable
{
    public string $collectionType = Meta::class;

    public function __construct(array $data = [])
    {
        parent::__construct($this->collectionType, $data);
    }

    public function __clone()
    {
        $cloned = [];
        foreach ($this->getIterator() as $meta) {
            $cloned [] = clone $meta;
        }

        $this->data = $cloned;
    }

    /**
     * Returns array of metadata in WordPress way.
     *
     * @return array|void
     */
    public function toArray(): array
    {
        $origin = parent::toArray();
        if ($this->isEmpty()) {
            return $origin;
        }

        $result = [];
        foreach ($origin as $index => $value) {
            /** @var Meta $value */
            if (! isset($result[ $value->getKey() ])) {
                $result[ $value->getKey() ] = [];
            }

            $result[ $value->getKey() ] [] = $value->getValue();
        }

        return $result;
    }

    /**
     * Receives array of metadata in WordPress way.
     *
     * @return void
     */
    public function fromArray(array $data)
    {
        if (empty($data)) {
            return;
        }

        foreach ($data as $key => $value) {
            /**
             * Process meta array that is kind of
             * [
             *  [ 'key' => 'foo', 'value' => 'bar' ],
             * ]
             */
            if (is_numeric($key) && is_array($value) && isset($value['key'])) {
                $this->add(new $this->collectionType($value['key'], $value['value'] ?? null));
                continue;
            }

            /**
             * Process meta array that is kind of
             * [ 'key1' => ['value1','value2'], ]
             */
            $value = is_array($value) ? $value : [ $value ];
            if (empty($value)) {
                $value [] = null;
            }
            foreach ($value as $term) {
                $this->add(new $this->collectionType($key, $term));
            }
        }
    }
}
