<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsTwigComponent]
final class Badge
{
    public string $tone = 'gray';

    #[PostMount]
    public function validate(): void
    {
        Style::tone($this->tone);
    }

    public function classes(): string
    {
        return Style::tone($this->tone);
    }
}
