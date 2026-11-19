<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Component;

use Foresi\Ui\Column\ListColumnRegistry;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * A short, fixed set of options of which any number can be chosen, shown as
 * toggle chips. Each chip is a real checkbox, so the form posts a list. Given the list attribute
 * the chips choose (or the tones of the values), a chosen chip wears the look of the value's
 * Ui:Badge, so a value looks the same in the form, the list and the detail.
 */
#[AsTwigComponent]
final class ChoiceChips
{
    /** A chosen chip without tones: the green of a selected control. */
    private const string CHECKED = 'has-[:checked]:border-green has-[:checked]:bg-green-tint has-[:checked]:text-green';

    /** A chosen chip with a tone: the fill and text of the Ui:Badge of that tone. */
    private const array CHECKED_TONES = [
        'gray' => 'has-[:checked]:border-transparent has-[:checked]:bg-badge-gray has-[:checked]:text-badge-gray-text',
        'green' => 'has-[:checked]:border-transparent has-[:checked]:bg-badge-green has-[:checked]:text-badge-green-text',
        'amber' => 'has-[:checked]:border-transparent has-[:checked]:bg-badge-amber has-[:checked]:text-badge-amber-text',
        'red' => 'has-[:checked]:border-transparent has-[:checked]:bg-badge-red has-[:checked]:text-badge-red-text',
    ];

    public string $name = '';
    public string $id = '';
    public string $legend = '';
    public ?string $help = null;
    public ?string $error = null;

    /**
     * @var array<string, string> value => label
     */
    public array $options = [];

    /**
     * @var list<string>
     */
    public array $values = [];

    /**
     * @var array<string, string> value => badge tone; empty keeps the green of a selected control,
     *                            and a value it leaves out is neutral
     */
    public array $tones = [];

    /** The list (such as "party.contacts") of $attribute. */
    public ?string $list = null;

    /** The attribute of $list whose tones the chosen chips wear. */
    public ?string $attribute = null;

    private bool $focused = false;

    public function __construct(private readonly ErrorFocus $errorFocus, private readonly ListColumnRegistry $columns)
    {
    }

    #[PostMount]
    public function validate(): void
    {
        if ('' === $this->name || '' === $this->id || '' === $this->legend) {
            throw new \InvalidArgumentException('Choice chips need a name, an id and a legend.');
        }
        if ([] === $this->options) {
            throw new \InvalidArgumentException('Choice chips need at least one option.');
        }
        foreach ($this->options as $value => $label) {
            if ('' === $label) {
                throw new \InvalidArgumentException(sprintf('Choice chip "%s" needs a non-empty label.', $value));
            }
        }
        if (null !== $this->attribute) {
            $attribute = $this->columns->attribute($this->list ?? throw new \InvalidArgumentException('Choice chips of an attribute name its list.'), $this->attribute);
            $this->tones = [];
            foreach (array_keys($this->options) as $value) {
                $this->tones[$value] = $attribute->tone($value);
            }
        }
        foreach ($this->tones as $value => $tone) {
            if (!isset($this->options[$value]) || !isset(self::CHECKED_TONES[$tone])) {
                throw new \InvalidArgumentException(\sprintf('Choice chip "%s" takes a badge tone and is one of its options.', $value));
            }
        }
        $this->focused = $this->errorFocus->claim(null !== $this->error);
    }

    /** The classes of a chosen chip of the value. */
    public function checkedClass(int|string $value): string
    {
        return [] === $this->tones ? self::CHECKED : self::CHECKED_TONES[$this->tones[$value] ?? 'gray'];
    }

    public function isChecked(int|string $value): bool
    {
        return \in_array((string) $value, $this->values, true);
    }

    public function describedBy(): ?string
    {
        $ids = array_filter([
            null !== $this->help ? $this->id.'-help' : null,
            null !== $this->error ? $this->id.'-error' : null,
        ]);

        return [] === $ids ? null : implode(' ', $ids);
    }

    /** Whether the group is the first invalid control of the page; its first option takes the focus. */
    public function focused(): bool
    {
        return $this->focused;
    }
}
