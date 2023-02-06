<?php

namespace iTRON\wpConnections\Exceptions;

use Throwable;

class ConnectionWrongData extends Exception {
    public function __construct( string $message = '', Throwable $previous = null ) {
        parent::__construct( $message, 3, $previous );
    }
}
