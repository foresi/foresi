<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * A search box without a visible label, for a toolbar next to the list it
 * filters. The label is read by assistive technology.
 */
#[AsTwigComponent]
final class SearchField extends AbstractControl
{
    public string $value = '';
    public ?string $placeholder = null;
}
