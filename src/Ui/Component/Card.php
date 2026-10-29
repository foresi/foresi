<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsTwigComponent]
final class Card
{
    public ?string $title = null;
    public ?string $icon = null;

    #[PostMount]
    public function validate(): void
    {
        Style::icon($this->icon);
    }
}
