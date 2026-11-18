<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsTwigComponent]
final class Tabs
{
    /**
     * @var list<array<string, mixed>> each with a string label and href, optional int count and bool active
     */
    public array $items = [];
    public ?string $label = null;
    /**
     * @var list<array<string, mixed>> the views past the tabs, as the items, in a "More views" menu after them (saved views, D7)
     */
    public array $more = [];
    /** The label of the menu. */
    public ?string $moreLabel = null;
    public string $moreId = 'more-views';

    #[PostMount]
    public function validate(): void
    {
        if ([] !== $this->more && (null === $this->moreLabel || '' === $this->moreLabel)) {
            throw new \InvalidArgumentException('The "More views" menu of the tabs needs its label.');
        }
        foreach ([...$this->items, ...$this->more] as $item) {
            if (!isset($item['label'], $item['href']) || !is_string($item['label']) || !is_string($item['href'])) {
                throw new \InvalidArgumentException('Every tab item needs a string label and href.');
            }
            Style::href($item['href']);
        }
    }

    /**
     * Whether the view shown is one of the menu's.
     */
    public function moreActive(): bool
    {
        return [] !== array_filter($this->more, static fn (array $item): bool => true === ($item['active'] ?? false));
    }
}
