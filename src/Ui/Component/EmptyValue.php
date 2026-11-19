<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * A value that is not there: a quiet outlined badge that says so in words ("Not set"),
 * in a list cell and on a detail page alike. Words rather than a dash, because a screen
 * reader may skip a dash (GitLab Pajamas table, VA.gov table: never leave a value blank).
 * Outlined and unfilled, so it never reads as a grey status badge.
 */
#[AsTwigComponent]
final class EmptyValue
{
    /** The words to show; null means the general "Not set". */
    public ?string $label = null;
}
