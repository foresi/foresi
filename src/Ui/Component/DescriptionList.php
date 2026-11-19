<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * Label and value pairs, one per row, for the details inside a card. A value is anything
 * Ui:Value shows (a text, a badge, badges, links, or not set), shown by Ui:Value, so it looks
 * as it does in a list cell; a row can carry a short note under its label.
 */
#[AsTwigComponent]
final class DescriptionList
{
    /**
     * @var list<array<string, mixed>> each with a string label and a value as Ui:Value takes it (null or '' for not set, shown as Ui:EmptyValue with an optional "empty" label), optional string note
     */
    public array $items = [];

    /** @var list<array{label: string, value: mixed, empty: ?string, note: ?string}> */
    private array $views = [];

    #[PostMount]
    public function validate(): void
    {
        foreach ($this->items as $item) {
            $value = $item['value'] ?? null;
            Value::view($value);
            $note = $item['note'] ?? null;
            if (null !== $note && !\is_string($note)) {
                throw new \InvalidArgumentException('A description note must be a string.');
            }
            $this->views[] = [
                'label' => Style::requireString($item, 'label', 'A description'),
                'value' => $value,
                'empty' => Style::optionalString($item, 'empty', 'A description'),
                'note' => $note,
            ];
        }
    }

    /**
     * @return list<array{label: string, value: mixed, empty: ?string, note: ?string}>
     */
    public function views(): array
    {
        return $this->views;
    }
}
