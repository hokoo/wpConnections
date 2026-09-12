<?php

namespace iTRON\wpConnections\Internal;

use iTRON\wpConnections\Exceptions\ConnectionWrongData;

final class ConnectionIdNormalizer
{
    private const ONE_ERROR = 'Positive integer ID expected.';
    private const MANY_ERROR = 'Positive integer ID or a non-empty array of positive integer IDs expected.';

    /**
     * @param mixed $value
     *
     * @throws ConnectionWrongData
     */
    public static function one($value): int
    {
        if (is_int($value)) {
            if (0 < $value) {
                return $value;
            }

            throw new ConnectionWrongData(self::ONE_ERROR);
        }

        if (! is_string($value) || ! preg_match('/^[0-9]+$/D', $value)) {
            throw new ConnectionWrongData(self::ONE_ERROR);
        }

        $digits = ltrim($value, '0');
        if ('' === $digits || self::exceedsPhpInteger($digits)) {
            throw new ConnectionWrongData(self::ONE_ERROR);
        }

        return (int) $digits;
    }

    /**
     * @param mixed $values
     *
     * @return int[]
     * @throws ConnectionWrongData
     */
    public static function many($values): array
    {
        $values = is_array($values) ? $values : [ $values ];
        if ([] === $values) {
            throw new ConnectionWrongData(self::MANY_ERROR);
        }

        $normalized = [];
        foreach ($values as $value) {
            try {
                $id = self::one($value);
            } catch (ConnectionWrongData $exception) {
                throw new ConnectionWrongData(self::MANY_ERROR, 300, $exception);
            }

            $normalized[ $id ] = $id;
        }

        return array_values($normalized);
    }

    private static function exceedsPhpInteger(string $digits): bool
    {
        $maximum = (string) PHP_INT_MAX;

        return strlen($digits) > strlen($maximum) ||
            (strlen($digits) === strlen($maximum) && 0 < strcmp($digits, $maximum));
    }
}
