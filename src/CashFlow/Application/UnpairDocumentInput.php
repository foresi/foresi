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
 * A document that does not belong to the occurrence it is paired or proposed with.
 */
#[Operation(
    name: 'cashflow.pairings.unpair',
    method: 'DELETE',
    path: '/api/v1/cash-flow/pairings/{subjectType}/{subjectId}',
    tool: 'foresi_unpair_document',
    scope: 'cashflow:write',
    title: 'Unpair a document from its planned occurrence',
    description: 'Says that a document belongs to none of the planned occurrences it was paired or proposed with: the occurrence counts in the forecast again, and Cash flow never pairs this document on its own again; only foresi_pair_document does. The proposed candidates are kept. The other documents of the supplier in that period are looked at again. Unpairing a document already unpaired changes nothing; a document with no pairing is not found.',
    response: 'CashFlowPairing',
)]
final readonly class UnpairDocumentInput
{
    public function __construct(
        #[Field('The type of the document, such as "received_document".', maxLength: DocumentPairing::MAX_SUBJECT_TYPE)]
        public string $subjectType,
        #[Field('The id of the document.', maxLength: DocumentPairing::MAX_SUBJECT_ID)]
        public string $subjectId,
    ) {
    }
}
