<?php

namespace iTRON\wpConnections\Abstracts;

interface IGetSet
{
    public function get(string $field);
    public function set(string $field, $value);
}
