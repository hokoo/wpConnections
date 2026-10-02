<?php

namespace iTRON\wpConnections;

use iTRON\wpConnections\Abstracts\Storage;
use iTRON\wpConnections\Exceptions\ClientRegisterFail;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Throwable;

class Factory
{
    /**
     * @throws ClientRegisterFail
     */
    public static function getStorage(Client $client): Storage
    {
        return self::create(
            'wpConnections/factory/getStorage/class',
            WPStorage::class,
            Storage::class,
            'storage',
            $client
        );
    }

    /**
     * @throws ClientRegisterFail
     */
    public static function getRestApi(Client $client): ClientRestApi
    {
        return self::create(
            'wpConnections/factory/getRestApi/class',
            ClientRestApi::class,
            ClientRestApi::class,
            'REST API',
            $client
        );
    }

    /**
     * @throws ClientRegisterFail
     */
    public static function getLogger(Client $client): LoggerInterface
    {
        return self::create(
            'wpConnections/factory/getLogger/class',
            Logger::class,
            LoggerInterface::class,
            'Logger',
            $client
        );
    }

    private static function create(
        string $hook,
        string $default,
        string $contract,
        string $label,
        Client $client
    ): object {
        try {
            $class = apply_filters($hook, $default, $client);
            $exists = is_string($class) && class_exists($class);
        } catch (Throwable $failure) {
            throw new ClientRegisterFail("A {$label} class could not be selected. See filter hooks [{$hook}]", 4, $failure);
        }
        if (! $exists) {
            throw new ClientRegisterFail("A {$label} class does not exist. See filter hooks [{$hook}]");
        }
        if (! is_a($class, $contract, true)) {
            throw new ClientRegisterFail("A {$label} class does not satisfy {$contract}. See filter hooks [{$hook}]");
        }
        if (! (new ReflectionClass($class))->isInstantiable()) {
            throw new ClientRegisterFail("A {$label} class is not constructible. See filter hooks [{$hook}]");
        }

        try {
            return new $class($client);
        } catch (Throwable $failure) {
            if (WPStorage::class === $class && $failure instanceof ClientRegisterFail) {
                throw $failure;
            }
            throw new ClientRegisterFail("A {$label} class could not be constructed. See filter hooks [{$hook}]", 4, $failure);
        }
    }
}
