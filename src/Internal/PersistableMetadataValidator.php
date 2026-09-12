<?php

namespace iTRON\wpConnections\Internal;

use iTRON\wpConnections\Exceptions\ConnectionWrongData;
use iTRON\wpConnections\MetaCollection;

/**
 * Validates metadata that is about to be persisted.
 *
 * Query metadata deliberately permits null as a removal wildcard, so this
 * validation belongs at mutation boundaries rather than in the value object.
 *
 * @internal
 */
final class PersistableMetadataValidator
{
    public static function assertValid(MetaCollection $metadata): void
    {
        foreach ($metadata->getIterator() as $meta) {
            if ('' === $meta->getKey()) {
                throw new ConnectionWrongData('Meta key cannot be empty.');
            }

            if (null === $meta->getValue()) {
                throw new ConnectionWrongData('Persisted meta value cannot be null.');
            }
        }
    }
}
