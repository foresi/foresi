<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * Label and value pairs, one per row, for the details inside a card. A value
 * is plain text or a badge; a row can carry a short note under its label.
 */
#[AsTwigComponent]
final class DescriptionList
{
    /**
     * @var list<array<string, mixed>> each with a string label and either a string value or a badge (label, tone), optional string note
     */
    public array $items = [];

    /** @var list<array{label: string, value: ?string, badge: ?array{label: string, tone: string}, note: ?string}> */
    private array $views = [];

    #[PostMount]
    public function validate(): void
    {
        foreach ($this->items as $item) {
            $badge = isset($item['badge']) ? Style::badge($item['badge'], 'A description') : null;
            $value = null === $badge ? Style::requireString($item, 'value', 'A description') : null;
            $note = $item['note'] ?? null;
            if (null !== $note && !\is_string($note)) {
                throw new \InvalidArgumentException('A description note must be a string.');
            }
            $this->views[] = [
                'label' => Style::requireString($item, 'label', 'A description'),
                'value' => $value,
                'badge' => $badge,
                'note' => $note,
            ];
        }
    }

    /**
     * @return list<array{label: string, value: ?string, badge: ?array{label: string, tone: string}, note: ?string}>
     */
    public function views(): array
    {
        return $this->views;
    }
}
