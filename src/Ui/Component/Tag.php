<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsTwigComponent]
final class Tag
{
    public string $label = '';
    public string $color = '#999999';

    #[PostMount]
    public function validate(): void
    {
        if (1 !== preg_match('/^#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?$/', $this->color)) {
            throw new \InvalidArgumentException(sprintf('Tag colour must be a hex colour, "%s" given.', $this->color));
        }
    }
}
