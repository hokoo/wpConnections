<?php

namespace iTRON\wpConnections\Exceptions;

class Exception extends \Exception
{
    private array $missingParams = [];

    public function __construct($message = '', $code = 4, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function setParam(string $param): self
    {
        $this->missingParams [] = $param;
        $this->setMessage($param . ' ');

        return $this;
    }

    public function getParams(): array
    {
        return $this->missingParams;
    }

    public function setMessage(string $message, bool $append = true)
    {
        $this->message = $append ? $this->message . $message : $message;
    }

    public function setCode(int $code)
    {
        $this->code = $code;
    }
}
