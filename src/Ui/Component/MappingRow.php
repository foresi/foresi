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
 * select, a few sample values sit below it. A choice the user should check
 * (a guess) carries a "Check this" marker next to the select and a short
 * reason under it, both linked to the select with aria-describedby, so the
 * marker is text, not colour alone. Confident choices carry nothing. Rows are
 * stacked in a divided list inside a card. Errors (one or more messages about
 * this select) are listed under it, and mark the select invalid; the first
 * invalid control of a page takes the focus.
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

    /** Why the current choice should be checked; null when there is nothing to check. */
    public ?string $review = null;

    /** @var list<string> messages about this select, shown under it */
    public array $errors = [];

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
        if (null !== $this->review && '' === trim($this->review)) {
            throw new \InvalidArgumentException('The review note of a mapping row must be a non-empty string.');
        }
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

    public function needsReview(): bool
    {
        return null !== $this->review;
    }

    /** The ids that describe the select: the review marker and note, then the errors. */
    public function describedBy(): string
    {
        $ids = [];
        if ($this->needsReview()) {
            $ids[] = $this->id.'-review';
            $ids[] = $this->id.'-review-note';
        }
        if ($this->invalid()) {
            $ids[] = $this->id.'-error';
        }

        return implode(' ', $ids);
    }

    public function selectClasses(): string
    {
        return 'h-control w-full appearance-none rounded-control border '.($this->invalid() ? 'border-badge-red-text' : 'border-control-line').' bg-white pl-2.5 pr-8 text-ink '.Style::FOCUS;
    }
}
