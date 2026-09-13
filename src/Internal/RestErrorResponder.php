<?php

namespace iTRON\wpConnections\Internal;

use iTRON\wpConnections\Client;
use iTRON\wpConnections\Exceptions\ConnectionEndpointNotFound;
use iTRON\wpConnections\Exceptions\ConnectionNotFound;
use iTRON\wpConnections\Exceptions\ConnectionWrongData;
use iTRON\wpConnections\Exceptions\MissingParameters;
use iTRON\wpConnections\Exceptions\RelationNotFound;
use iTRON\wpConnections\Exceptions\StorageCapabilityUnavailable;
use iTRON\wpConnections\Exceptions\StorageFailure;
use Throwable;
use WP_Error;

/**
 * Maps handler failures to the approved default REST v1 representation.
 *
 * @internal
 */
final class RestErrorResponder
{
    private const INTERNAL_CODE = 'wp_connections_internal_error';
    private const INTERNAL_MESSAGE = 'An internal error occurred.';
    private const INTERNAL_STATUS = 500;

    public static function fromThrowable(Client $client, Throwable $failure): WP_Error
    {
        $status = self::domainStatus($failure);
        if (null === $status || self::hasStorageFailure($failure)) {
            self::logInternalFailure($client, $failure);

            return new WP_Error(
                self::INTERNAL_CODE,
                self::INTERNAL_MESSAGE,
                [ 'status' => self::INTERNAL_STATUS ]
            );
        }

        $domainCode = (int) $failure->getCode();

        return new WP_Error(
            $domainCode,
            $failure->getMessage(),
            [
                'status'      => $status,
                'domain_code' => $domainCode,
            ]
        );
    }

    private static function domainStatus(Throwable $failure): ?int
    {
        if ($failure instanceof RelationNotFound || $failure instanceof ConnectionNotFound) {
            return 404;
        }

        if ($failure instanceof ConnectionEndpointNotFound) {
            return 404;
        }

        if ($failure instanceof MissingParameters) {
            return 400;
        }

        if ($failure instanceof ConnectionWrongData) {
            return in_array($failure->getCode(), [ 301, 302, 303 ], true)
                ? 409
                : 400;
        }

        return null;
    }

    private static function hasStorageFailure(Throwable $failure): bool
    {
        do {
            if (
                $failure instanceof StorageFailure
                || $failure instanceof StorageCapabilityUnavailable
            ) {
                return true;
            }

            $failure = $failure->getPrevious();
        } while ($failure instanceof Throwable);

        return false;
    }

    private static function logInternalFailure(Client $client, Throwable $failure): void
    {
        try {
            $client->getLogger()->error(
                'wpConnections REST request failed.',
                [ 'exception' => $failure ]
            );
        } catch (Throwable $loggingFailure) {
            // Diagnostics must never alter the public failure response.
        }
    }
}
