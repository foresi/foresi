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

    #[PostMount]
    public function validate(): void
    {
        Style::button($this->variant, $this->size);
    }

    public function classes(): string
    {
        return Style::button($this->variant, $this->size);
    }
}
