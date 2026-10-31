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
 * Errors (one or more messages about this select) are listed under it, and
 * mark the select invalid; the first invalid control of a page takes the focus.
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

    /** @var list<string> messages about this select, shown under it */
    public array $errors = [];

    /** @var array{label: string, tone: string}|null */
    private ?array $badgeView = null;

    private bool $focused = false;

    public function __construct(private readonly ErrorFocus $errorFocus)
    {
    }

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
        foreach ($this->errors as $error) {
            if ('' === trim($error)) {
                throw new \InvalidArgumentException('An error of a mapping row must be a non-empty string.');
            }
        }
        $this->badgeView = null === $this->badge ? null : Style::badge($this->badge, 'A mapping row');
        $this->focused = $this->errorFocus->claim($this->invalid());
    }

    public function invalid(): bool
    {
        return [] !== $this->errors;
    }

    public function focused(): bool
    {
        return $this->focused;
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
        return 'h-control w-full appearance-none rounded-control border '.($this->invalid() ? 'border-badge-red-text' : 'border-control-line').' bg-white pl-2.5 pr-8 text-ink '.Style::FOCUS;
    }
}
