<?php

namespace iTRON\wpConnections\RestResponse;

use iTRON\wpConnections\Abstracts\IArrayConvertable;
use WP_REST_Response;

class CollectionItem extends WP_REST_Response
{
    public $links;

    public function __construct(IArrayConvertable $data, $status = 200, $headers = [])
    {
        parent::__construct($data->toArray(), $status, $headers);

        unset($this->headers);
        unset($this->status);
    }
}
