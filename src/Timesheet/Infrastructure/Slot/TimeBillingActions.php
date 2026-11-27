<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Infrastructure\Slot;

/**
 * The actions that bill logged time on the time report, such as those of the
 * Invoicing integration (Integration/Invoicing/). The report shows them to an
 * owner only, because only an owner sees rates. A provider answers with items
 * that have a template. On a project, billing is an item of Work's
 * ProjectSlots::HEADER_ACTIONS.
 */
final class TimeBillingActions
{
    /** The primary action of the time report. Context: clientId, the client of the filter or null. */
    public const string REPORT = 'timesheet.time_billing.report';

    private function __construct()
    {
    }
}
