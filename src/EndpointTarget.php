<?php

namespace iTRON\wpConnections;

use InvalidArgumentException;

/** Explicit endpoint projection, independent of relation.type. */
final class EndpointTarget
{
    private string $target;
    private ?string $selector;
    private ?int $anchor;

    private function __construct(string $target, ?string $selector = null, ?int $anchor = null)
    {
        $this->target = $target;
        $this->selector = $selector;
        $this->anchor = $anchor;
    }

    public static function from(): self
    {
        return new self('from');
    }

    public static function to(): self
    {
        return new self('to');
    }

    public static function both(): self
    {
        return new self('both');
    }

    /**
     * The single selector names the physical role or incident-endpoint search
     * that selected these rows. Combined selectors cannot define an opposite.
     */
    public static function opposite(string $selector, int $anchor): self
    {
        if (! in_array($selector, [ 'from', 'to', 'both' ], true) || 0 >= $anchor) {
            throw new InvalidArgumentException('Opposite requires one valid selector and positive anchor.');
        }

        return new self('opposite', $selector, $anchor);
    }

    public function roles(Connection $connection): array
    {
        if ('both' === $this->target) {
            return [ 'from', 'to' ];
        }

        if ('opposite' !== $this->target) {
            return [ $this->target ];
        }

        if ($connection->from === $connection->to) {
            return [];
        }

        if ('from' === $this->selector) {
            return $connection->from === $this->anchor ? [ 'to' ] : [];
        }

        if ('to' === $this->selector) {
            return $connection->to === $this->anchor ? [ 'from' ] : [];
        }

        if ($connection->from === $this->anchor && $connection->to !== $this->anchor) {
            return [ 'to' ];
        }

        if ($connection->to === $this->anchor && $connection->from !== $this->anchor) {
            return [ 'from' ];
        }

        return [];
    }
}
