<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * A group of Ui:MenuItem rows: an optional uppercase label, and a divider above every
 * group but the first (level.io's view switcher and select groups).
 */
#[AsTwigComponent]
final class MenuSection
{
    public ?string $label = null;
}
