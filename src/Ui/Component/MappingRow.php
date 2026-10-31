<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * One source item mapped to a target: a column of an import file and the
 * field it fills, or a source value and what it means. The label names the
 * select, a few sample values sit below it, a badge says why the current
 * choice was proposed. Rows are stacked in a divided list inside a card.
 */
#[AsTwigComponent]
final class MappingRow
{
    private const int MAX_SAMPLES = 3;

    public string $id = '';
    public string $name = '';
    public string $label = '';
    public string $value = '';

    /** @var list<string> sample values; at most three are shown */
    public array $samples = [];

    /**
     * @var array<string, string|array<string, string>> value => label, or a group label => (value => label)
     */
    public array $options = [];

    /** @var array<string, mixed>|null label and tone */
    public ?array $badge = null;

    /** @var array{label: string, tone: string}|null */
    private ?array $badgeView = null;

    #[PostMount]
    public function validate(): void
    {
        if ('' === $this->id || '' === $this->name || '' === $this->label) {
            throw new \InvalidArgumentException('A mapping row needs an id, a name and a label.');
        }
        if ([] === $this->options) {
            throw new \InvalidArgumentException('A mapping row needs options.');
        }
        foreach ($this->options as $label) {
            if (\is_array($label) && [] === $label) {
                throw new \InvalidArgumentException('An option group of a mapping row needs options.');
            }
        }
        $this->badgeView = null === $this->badge ? null : Style::badge($this->badge, 'A mapping row');
    }

    /**
     * @return list<string> the non-empty samples to show
     */
    public function shownSamples(): array
    {
        return \array_slice(array_values(array_filter($this->samples, static fn (string $sample): bool => '' !== trim($sample))), 0, self::MAX_SAMPLES);
    }

    /**
     * @return array{label: string, tone: string}|null
     */
    public function badgeView(): ?array
    {
        return $this->badgeView;
    }

    public function selectClasses(): string
    {
        return 'h-control w-full appearance-none rounded-control border border-line-strong bg-white pl-2.5 pr-8 text-ink '.Style::FOCUS;
    }
}
