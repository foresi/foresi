<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsTwigComponent]
final class Timeline
{
    /**
     * @var list<array<string, mixed>> each with a string time, icon and text, optional machine-readable datetime
     */
    public array $items = [];

    /** @var list<array{time: string, icon: string, text: string, datetime: ?string}> */
    private array $views = [];

    #[PostMount]
    public function validate(): void
    {
        foreach ($this->items as $item) {
            $icon = Style::requireString($item, 'icon', 'A timeline entry');
            Style::icon($icon);
            $datetime = $item['datetime'] ?? null;
            $this->views[] = [
                'time' => Style::requireString($item, 'time', 'A timeline entry'),
                'icon' => $icon,
                'text' => Style::requireString($item, 'text', 'A timeline entry'),
                'datetime' => is_string($datetime) && '' !== $datetime ? $datetime : null,
            ];
        }
    }

    /**
     * @return list<array{time: string, icon: string, text: string, datetime: ?string}>
     */
    public function views(): array
    {
        return $this->views;
    }
}
