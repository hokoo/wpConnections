<?php

namespace iTRON\wpConnections\RestResponse;

use WP_REST_Response;

class CollectionItem extends WP_REST_Response {
    public $links;

    public function __construct($data = null, $status = 200, $headers = array()){
        parent::__construct($data, $status, $headers);

        unset( $this->headers );
        unset( $this->status );
    }
}
