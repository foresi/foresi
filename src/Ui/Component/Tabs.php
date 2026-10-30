<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

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
