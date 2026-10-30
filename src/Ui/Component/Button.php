<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsTwigComponent]
final class Button
{
    public string $variant = 'secondary';
    public string $size = 'md';
    public ?string $icon = null;
    public ?string $href = null;
    public string $type = 'button';
    public bool $pressed = false;

    #[PostMount]
    public function validate(): void
    {
        Style::button($this->variant, $this->size);
        Style::icon($this->icon);
        Style::href($this->href);
        if ($this->pressed && null !== $this->href) {
            throw new \InvalidArgumentException('A link cannot be pressed; pressed applies to buttons only.');
        }
    }

    public function classes(): string
    {
        return Style::button($this->variant, $this->size, false, $this->pressed);
    }
}
