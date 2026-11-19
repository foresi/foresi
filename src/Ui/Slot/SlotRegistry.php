<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Slot;

use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;
use Foresi\Ui\Contract\Slot\Slots;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Twig\Attribute\AsTwigFunction;

/**
 * The registry of the screen slots (Slots), filled by tag like the navigation and the dashboard.
 */
final readonly class SlotRegistry
{
    /**
     * @param iterable<SlotProvider> $providers
     */
    public function __construct(
        #[AutowireIterator(Slots::TAG)]
        private iterable $providers,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return list<SlotItem> by weight, then in the order the providers are registered
     */
    #[AsTwigFunction('slot_items')]
    public function items(string $slot, array $context = []): array
    {
        $items = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->items($slot, $context) as $item) {
                $items[] = $item;
            }
        }
        usort($items, static fn (SlotItem $a, SlotItem $b): int => $a->weight <=> $b->weight);

        return $items;
    }
}
