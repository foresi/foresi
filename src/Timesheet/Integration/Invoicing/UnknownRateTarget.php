<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Integration\Invoicing;

/**
 * A project or a person that is not in the active organisation.
 */
final class UnknownRateTarget extends \RuntimeException
{
}
