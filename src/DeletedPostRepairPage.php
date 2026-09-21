<?php

namespace iTRON\wpConnections;

use InvalidArgumentException;

final class DeletedPostRepairPage
{
    /** @var DeletedPostRepairView[] */
    private array $items;
    private ?string $nextAfterKey;

    /**
     * @param DeletedPostRepairView[] $items
     */
    private function __construct(array $items, ?string $nextAfterKey)
    {
        foreach ($items as $item) {
            if (! $item instanceof DeletedPostRepairView) {
                throw new InvalidArgumentException('Repair pages contain only repair views.');
            }
        }
        if (null !== $nextAfterKey && ! preg_match('/^[a-f0-9]{64}$/D', $nextAfterKey)) {
            throw new InvalidArgumentException('Invalid deleted-post repair page cursor.');
        }

        $this->items = array_values($items);
        $this->nextAfterKey = $nextAfterKey;
    }

    /**
     * @internal Library-owned construction path; not part of the compatibility surface.
     * @param DeletedPostRepairView[] $items
     */
    public static function createInternal(array $items, ?string $nextAfterKey): self
    {
        return new self($items, $nextAfterKey);
    }

    /**
     * @return DeletedPostRepairView[]
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getNextAfterKey(): ?string
    {
        return $this->nextAfterKey;
    }
}
