<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Contract;

/**
 * The actions that bill logged time, filled by the module that bills it. Work offers the Ui slots
 * and shows them to an owner only, because only an owner sees rates; with no provider installed
 * there is no action. A provider answers with items that have a template.
 */
final class TimeBillingActions
{
    /** An item of the "⋯" menu of a project's list and board. Context: projectId. */
    public const string PROJECT = 'work.time_billing.project';

    /** The primary action of the time report. Context: clientId, the client of the filter or null. */
    public const string REPORT = 'work.time_billing.report';
}
