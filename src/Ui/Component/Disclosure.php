<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * A centred toggle that reveals the less-used fields of a form, as iDoklad's
 * "Show more details". It is a native <details>, so it works without
 * JavaScript; "open" shows it opened (a value or a mistake inside).
 */
#[AsTwigComponent]
final class Disclosure
{
    public string $label = '';
    public string $summaryId = '';
    public bool $open = false;

    #[PostMount]
    public function validate(): void
    {
        if ('' === $this->label) {
            throw new \InvalidArgumentException('A disclosure needs a label.');
        }
    }
}
