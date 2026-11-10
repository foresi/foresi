<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Timesheet\Application;

/**
 * A project or a person that is not in the active organisation.
 */
final class UnknownRateTarget extends \RuntimeException
{
}
