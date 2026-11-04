<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * The template was saved between reading it and locking it, so an invoice built
 * from what was read could mix two versions. Nothing was stored; build it again.
 */
final class RecurringChangedMeanwhile extends \RuntimeException
{
}
