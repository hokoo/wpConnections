<?php

namespace iTRON\wpConnections\Abstracts;

use iTRON\wpConnections\MetaCollection;

class Connection implements IArrayConvertable {

    public int $id = 0;
    protected string $title = '';
    public string $relation = '';
    public int $from;
    public int $to;
    public int $order;
    public MetaCollection $meta;

    public function __construct() {
        $this->meta = new MetaCollection();
    }

    public function toArray(): array {
        return [
            'id'        => $this->id,
            'title'     => $this->title,
            'relation'  => $this->relation,
            'from'      => $this->from,
            'to'        => $this->to,
            'order'     => $this->order,
            'meta'      => $this->meta->toArray(),
        ];
    }
}
