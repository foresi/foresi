<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * Label, help and error text around a control that is passed as the content.
 */
#[AsTwigComponent]
final class Field
{
    public string $id = '';
    public string $label = '';
    public ?string $help = null;
    public ?string $error = null;
    public bool $required = false;
    public bool $inline = false;

    #[PostMount]
    public function validate(): void
    {
        if ('' === $this->id) {
            throw new \InvalidArgumentException('Field requires the id of its control.');
        }
    }
}
