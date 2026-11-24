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
     * @var list<array<string, mixed>> each with a string label and href, optional int count, bool active and bool modified (a dot: the view shows unsaved changes)
     */
    public array $items = [];
    public ?string $label = null;
    /** Whether the tabs draw their own rail; off where the list's toolbar row draws it under the tabs and its tools. */
    public bool $rail = true;

    #[PostMount]
    public function validate(): void
    {
        foreach ($this->items as $item) {
            if (!isset($item['label'], $item['href']) || !is_string($item['label']) || !is_string($item['href'])) {
                throw new \InvalidArgumentException('Every tab item needs a string label and href.');
            }
            Style::href($item['href']);
        }
    }
}
