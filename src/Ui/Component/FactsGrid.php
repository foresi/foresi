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
     * @var list<array<string, mixed>> each with a string label and value, optional bool big and an
     *                                 optional href that turns the value into a link (checked like every href)
     */
    public array $items = [];

    /** @var list<array{label: string, value: string, big: bool, href: string|null}> */
    private array $views = [];

    #[PostMount]
    public function validate(): void
    {
        foreach ($this->items as $item) {
            $this->views[] = [
                'label' => Style::requireString($item, 'label', 'A fact'),
                'value' => Style::requireString($item, 'value', 'A fact'),
                'big' => true === ($item['big'] ?? false),
                'href' => $this->href($item),
            ];
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function href(array $item): ?string
    {
        $href = $item['href'] ?? null;
        if (null !== $href && !\is_string($href)) {
            throw new \InvalidArgumentException('A fact link must be a string.');
        }
        Style::href($href);

        return $href;
    }

    /**
     * @return list<array{label: string, value: string, big: bool, href: string|null}>
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
