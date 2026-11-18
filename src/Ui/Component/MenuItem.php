<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * One row of a Ui:Menu: a link with "href", otherwise a button ("type" submit inside a form).
 * "checked" makes it a radio row (menuitemradio) with a check when on; "tone" danger is the
 * red destructive row; a disabled row is not focusable by click and says so to assistive tech.
 * "size" sm is the compact row a data table's row menu uses.
 */
#[AsTwigComponent]
final class MenuItem
{
    private const array TONES = [
        'default' => 'hover:bg-frame focus-visible:bg-frame aria-checked:bg-green-tint',
        'danger' => 'text-badge-red-text hover:bg-badge-red focus-visible:bg-badge-red',
    ];

    public ?string $href = null;
    public string $type = 'button';
    public ?string $icon = null;
    public string $tone = 'default';
    /** Null for a plain row; true or false for a radio row. */
    public ?bool $checked = null;
    public bool $disabled = false;
    /** md (the default) or sm, the compact row. */
    public string $size = 'md';

    #[PostMount]
    public function validate(): void
    {
        if (!in_array($this->type, ['button', 'submit'], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown menu item type "%s", expected button or submit.', $this->type));
        }
        Style::icon($this->icon);
        Style::href($this->href);
        $this->classes();
    }

    public function tag(): string
    {
        return match (true) {
            $this->disabled => 'span',
            null !== $this->href => 'a',
            default => 'button',
        };
    }

    public function classes(): string
    {
        $tone = self::TONES[$this->tone] ?? throw new \InvalidArgumentException(sprintf('Unknown menu item tone "%s", expected one of: %s.', $this->tone, implode(', ', array_keys(self::TONES))));

        $option = match ($this->size) {
            'md' => Menu::OPTION,
            'sm' => Menu::OPTION_SM,
            default => throw new \InvalidArgumentException(sprintf('Unknown menu item size "%s", expected md or sm.', $this->size)),
        };

        return $option.' '.($this->disabled ? 'cursor-default opacity-60' : $tone).' '.Style::FIELD_FOCUS;
    }
}
