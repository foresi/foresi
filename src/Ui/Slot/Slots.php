<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Slot;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Twig\Attribute\AsTwigFunction;

/**
 * The registry of screen slots, filled by tag like the navigation and the dashboard. A slot with no
 * provider installed is empty, and the screen renders without it.
 */
final readonly class Slots
{
    public const string TAG = 'app.slot_provider';

    /** Widgets above the page header of every shell page, such as the running timer. Context: member (bool). */
    public const string SHELL_WIDGETS = 'ui.shell_widgets';

    /** Items of the account menu. Context: shell (ShellView). */
    public const string USER_MENU = 'ui.user_menu';

    /** The last section of the account menu, such as sign-out. Context: shell (ShellView). */
    public const string USER_MENU_END = 'ui.user_menu_end';

    /**
     * @param iterable<SlotProvider> $providers
     */
    public function __construct(
        #[AutowireIterator(self::TAG)]
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
