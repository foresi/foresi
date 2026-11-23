<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

use Foresi\CashFlow\Domain\DocumentPairing;
use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * A received document that a person says belongs to an occurrence of a planned item.
 */
#[Operation(
    name: 'cashflow.pairings.pair',
    method: 'POST',
    path: '/api/v1/cash-flow/pairings',
    tool: 'foresi_pair_document',
    scope: 'cashflow:write',
    title: 'Pair a document with a planned occurrence',
    description: 'Says that a document belongs to one occurrence of a planned item, so the forecast no longer counts that occurrence and Cash flow never changes the pairing on its own. Replaces what was there before, a proposal or an automatic pairing included. The day must be a day the item falls on (see foresi_list_planned_items), not skipped and not after its end, and the document and the item must move money the same way. Pairing again with the same occurrence changes nothing.',
    response: 'CashFlowPairing',
)]
final readonly class PairDocumentInput
{
    public function __construct(
        #[Field('The type of the document, such as "received_document".', maxLength: DocumentPairing::MAX_SUBJECT_TYPE)]
        public string $subjectType,
        #[Field('The id of the document.', maxLength: DocumentPairing::MAX_SUBJECT_ID)]
        public string $subjectId,
        #[Field('The id of the planned item.', format: 'uuid')]
        public string $plannedItemId,
        #[Field('The day of the occurrence, ISO 8601.', format: 'date')]
        public string $occurrenceOn,
    ) {
    }
}
