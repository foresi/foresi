<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * The occurrence is not the template's to issue any more: it was issued, by another
 * worker too, or the template is no longer active or has moved on to another date.
 * Nothing was stored and no number was used up.
 */
final class OccurrenceTaken extends \RuntimeException
{
}
