<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsTwigComponent]
final class FactsGrid
{
    private const int MAX_COLUMNS = 6;

    /**
     * @var list<array<string, mixed>> each with a string label and value, optional bool big
     */
    public array $items = [];

    /** @var list<array{label: string, value: string, big: bool}> */
    private array $views = [];

    #[PostMount]
    public function validate(): void
    {
        foreach ($this->items as $item) {
            $this->views[] = [
                'label' => Style::requireString($item, 'label', 'A fact'),
                'value' => Style::requireString($item, 'value', 'A fact'),
                'big' => true === ($item['big'] ?? false),
            ];
        }
    }

    /**
     * @return list<array{label: string, value: string, big: bool}>
     */
    public function views(): array
    {
        return $this->views;
    }

    public function columns(): int
    {
        return min(count($this->views), self::MAX_COLUMNS);
    }
}
