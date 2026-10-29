<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsTwigComponent]
final class KpiStrip
{
    private const int MAX_ITEMS = 4;

    /**
     * @var list<array<string, mixed>> each with a string label and value, optional note and badge (label, tone)
     */
    public array $items = [];

    /** @var list<array{label: string, value: string, note: ?string, badge: ?array{label: string, tone: string}}> */
    private array $views = [];

    #[PostMount]
    public function validate(): void
    {
        $count = count($this->items);
        if ($count < 1 || $count > self::MAX_ITEMS) {
            throw new \InvalidArgumentException(sprintf('A KPI strip shows 1 to %d items.', self::MAX_ITEMS));
        }
        foreach ($this->items as $item) {
            $note = $item['note'] ?? null;
            $this->views[] = [
                'label' => Style::requireString($item, 'label', 'A KPI'),
                'value' => Style::requireString($item, 'value', 'A KPI'),
                'note' => is_string($note) && '' !== $note ? $note : null,
                'badge' => isset($item['badge']) ? Style::badge($item['badge'], 'A KPI') : null,
            ];
        }
    }

    /**
     * @return list<array{label: string, value: string, note: ?string, badge: ?array{label: string, tone: string}}>
     */
    public function views(): array
    {
        return $this->views;
    }
}
