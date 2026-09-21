<?php

namespace iTRON\wpConnections\Tests;

use iTRON\wpConnections\Client;
use ReflectionClass;

/**
 * Initializes the production context boundary for lightweight unit-test Clients.
 */
final class DeletedPostRepairTestClientContext
{
    public static function initialize(
        Client $client,
        int $siteId = 1,
        string $sitePrefix = 'wp_'
    ): void {
        $clientClass = new ReflectionClass(Client::class);
        $siteIdProperty = $clientClass->getProperty('deletedPostRepairSiteId');
        $sitePrefixProperty = $clientClass->getProperty('deletedPostRepairSitePrefix');
        $siteIdProperty->setValue($client, $siteId);
        $sitePrefixProperty->setValue($client, $sitePrefix);
    }
}
