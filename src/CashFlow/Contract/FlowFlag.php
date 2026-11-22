<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

/**
 * What a reader should know about an expected flow. The forecast adds overdue
 * and expected_date itself; a source sets the others.
 */
enum FlowFlag: string
{
    /** Its date was before today, so the forecast counts it in the first period. */
    case Overdue = 'overdue';
    /** The amount is an estimate, not a document's amount. */
    case Estimated = 'estimated';
    /** The document has no due date, so the source chose a date. */
    case NoDueDate = 'no_due_date';
    /** Someone set the date it is expected on, which replaced the source's date. */
    case ExpectedDate = 'expected_date';
    /**
     * Its date has passed, but it is still awaited and not late, such as a tax
     * whose final amount only the return knows. The forecast counts it in the
     * first period, never as overdue. A source sets it on a flow dated before
     * today.
     */
    case Awaited = 'awaited';
}
