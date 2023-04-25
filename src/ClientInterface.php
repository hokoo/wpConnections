<?php

namespace iTRON\wpConnections;

trait ClientInterface
{
    private Client $client;

    public function getClient(): Client
    {
        return $this->client;
    }

    public function setClient(Client $client): self
    {
        $this->client = $client;
        return $this;
    }
}
