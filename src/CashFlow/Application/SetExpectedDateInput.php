<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\CashFlow\Application;

use App\CashFlow\Domain\ExpectedDate;
use App\Shared\Application\Api\Field;
use App\Shared\Application\Api\Operation;

/**
 * When a document is really expected to be paid.
 */
#[Operation(
    name: 'cashflow.expected_dates.set',
    method: 'POST',
    path: '/api/v1/cash-flow/expected-dates',
    tool: 'foresi_set_expected_date',
    scope: 'cashflow:write',
    title: 'Set when a document is expected to be paid',
    description: 'Sets the day a document is expected to be paid, which replaces its due date in the forecast, for example for an overdue invoice the customer promised to pay next week. The subject is the subject.type and subject.id of a forecast item with certainty document. Setting it again moves it; foresi_clear_expected_date removes it.',
    response: 'CashFlowExpectedDate',
)]
final readonly class SetExpectedDateInput
{
    public function __construct(
        #[Field('The type of the subject, as a forecast item names it, such as "invoice".', maxLength: ExpectedDate::MAX_SUBJECT_TYPE)]
        public string $subjectType,
        #[Field('The id of the subject, as a forecast item names it.', maxLength: ExpectedDate::MAX_SUBJECT_ID)]
        public string $subjectId,
        #[Field('The day it is expected to be paid, ISO 8601.', format: 'date')]
        public string $expectedOn,
    ) {
    }
}
