<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsTwigComponent]
final class IconButton
{
    public string $icon = '';
    public string $label = '';
    public string $variant = 'secondary';
    public string $size = 'md';
    public ?string $href = null;
    public string $type = 'button';

    #[PostMount]
    public function validate(): void
    {
        if ('' === trim($this->label)) {
            throw new \InvalidArgumentException('IconButton requires a label for its accessible name.');
        }
        if ('' === $this->icon) {
            throw new \InvalidArgumentException('IconButton requires an icon.');
        }
        Style::button($this->variant, $this->size, true);
    }

    public function classes(): string
    {
        return Style::button($this->variant, $this->size, true);
    }
}
