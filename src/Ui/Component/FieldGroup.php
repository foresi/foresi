<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * A small titled group of horizontal field rows. Its title sits over the
 * field column, so the group keeps the label grid of the rows around it.
 */
#[AsTwigComponent]
final class FieldGroup
{
    public string $title = '';
}
