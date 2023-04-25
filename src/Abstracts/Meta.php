<?php

namespace iTRON\wpConnections\Abstracts;

abstract class Meta implements IArrayConvertable
{
    public string $key;
    public $value;

    public function __construct(string $key = '', $value = null)
    {
        if (strlen($key)) {
            $this->key = $key;
            $this->value = $value;
        }
    }

    /**
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * @param mixed $value
     */
    public function setValue($value): void
    {
        $this->value = $value;
    }

    /**
     * @return string
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * @param string $key
     */
    public function setKey(string $key): void
    {
        $this->key = $key;
    }

    public function toArray(): array
    {
        return [
            'key'   => $this->getKey(),
            'value' => $this->getValue(),
        ];
    }
}
