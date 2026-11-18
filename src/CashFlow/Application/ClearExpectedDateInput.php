<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

use Foresi\CashFlow\Domain\ExpectedDate;
use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * A document that is expected on its due date again.
 */
#[Operation(
    name: 'cashflow.expected_dates.clear',
    method: 'DELETE',
    path: '/api/v1/cash-flow/expected-dates/{subjectType}/{subjectId}',
    tool: 'foresi_clear_expected_date',
    scope: 'cashflow:write',
    title: 'Clear when a document is expected to be paid',
    description: 'Removes the expected day of a document, so the forecast uses its due date again. Clearing one that is not set changes nothing.',
    response: 'CashFlowExpectedDate',
)]
final readonly class ClearExpectedDateInput
{
    public function __construct(
        #[Field('The type of the subject, such as "invoice".', maxLength: ExpectedDate::MAX_SUBJECT_TYPE)]
        public string $subjectType,
        #[Field('The id of the subject.', maxLength: ExpectedDate::MAX_SUBJECT_ID)]
        public string $subjectId,
    ) {
    }
}
