<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * A choice of one among a few options that each need a sentence of
 * explanation. Each card is a real radio button; an option whose consequence
 * the user must not miss carries a warning line.
 */
#[AsTwigComponent]
final class RadioCards
{
    private const int MAX_OPTIONS = 4;

    public string $name = '';
    public string $id = '';
    public string $legend = '';
    public ?string $error = null;
    public string $value = '';

    /**
     * @var list<array<string, mixed>> each with a string value, label and text, optional warning
     */
    public array $options = [];

    /** @var list<array{value: string, label: string, text: string, warning: ?string}> */
    private array $views = [];

    private bool $focused = false;

    public function __construct(private readonly ErrorFocus $errorFocus)
    {
    }

    #[PostMount]
    public function validate(): void
    {
        if ('' === $this->name || '' === $this->id || '' === $this->legend) {
            throw new \InvalidArgumentException('Radio cards need a name, an id and a legend.');
        }
        $count = \count($this->options);
        if ($count < 2 || $count > self::MAX_OPTIONS) {
            throw new \InvalidArgumentException(\sprintf('Radio cards offer 2 to %d options.', self::MAX_OPTIONS));
        }
        foreach ($this->options as $option) {
            $warning = $option['warning'] ?? null;
            if (null !== $warning && (!\is_string($warning) || '' === $warning)) {
                throw new \InvalidArgumentException('A radio card warning must be a non-empty string.');
            }
            $this->views[] = [
                'value' => Style::requireString($option, 'value', 'A radio card'),
                'label' => Style::requireString($option, 'label', 'A radio card'),
                'text' => Style::requireString($option, 'text', 'A radio card'),
                'warning' => $warning,
            ];
        }
        $this->focused = $this->errorFocus->claim(null !== $this->error);
    }

    /**
     * @return list<array{value: string, label: string, text: string, warning: ?string}>
     */
    public function views(): array
    {
        return $this->views;
    }

    /** Whether the group is the first invalid control of the page; its first option takes the focus. */
    public function focused(): bool
    {
        return $this->focused;
    }
}
