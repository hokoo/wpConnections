<?php

namespace iTRON\wpConnections;

use iTRON\wpConnections\Abstracts\ArrayConvertableCollectionTrait;
use iTRON\wpConnections\Abstracts\IArrayConvertable;
use Ramsey\Collection\Collection;

class ConnectionCollection extends Collection implements IArrayConvertable
{
    use ArrayConvertableCollectionTrait;

    public function __construct(array $data = [])
    {
        $collectionType = __NAMESPACE__ . '\Connection';
        parent::__construct($collectionType, $this->fromArray($data, $collectionType));
    }

    /**
     * @TODO
     *
     * Returns WP_Post[] based on 'from' or 'to' direction type.
     */
    public function getPosts(string $direction)
    {
    }

    private function fromArray(array $items, string $collectionType = ''): array
    {

        $connections = [];
        foreach ($items as $item) {
            if ($item instanceof $collectionType) {
                $connections [] = $item;
                continue;
            }

            $item = (object) $item;

            if (! isset($item->ID)) {
                continue;
            }
            if (! isset($item->from)) {
                continue;
            }
            if (! isset($item->to)) {
                continue;
            }

            $connectionQuery = new Query\Connection();
            $connectionQuery
                ->set('id', $item->ID)
                ->set('from', $item->from)
                ->set('to', $item->to)
                ->set('order', $item->order ?? 0)
                ->set('title', $item->title ?? '')
                ->set('client', $item->client ?? null)
                ->set('relation', $item->relation ?? null);

            $connection = new Connection($connectionQuery);

            if (! empty($item->meta) && is_array($item->meta)) {
                $connection->meta->fromArray($item->meta);
            }

            $connections [] = $connection;
        }

        return $connections;
    }

    /**
     * @return Connection
     */
    public function first(): Connection
    {
        return parent::first();
    }

    /**
     * @return Connection
     */
    public function last(): Connection
    {
        return parent::last();
    }
}
