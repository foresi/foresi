<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * A band across a card that holds two field groups side by side, on the
 * same label grid as the rows above it, as iDoklad's dates and payment band.
 * It spans the card edge to edge on a subtle background. Below 1024 px the
 * groups stack into one column. Give it two Ui:FieldGroup as the content.
 */
#[AsTwigComponent]
final class FormBand
{
}
