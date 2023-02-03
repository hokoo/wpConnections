<?php

namespace iTRON\wpConnections\Exceptions;

class Exception extends \Exception {
    public function setMessage( string $message, bool $append = true ) {
        $this->message = $append ? $this->message . $message : $message;
    }

    public function setCode( int $code ) {
        $this->code = $code;
    }
}
