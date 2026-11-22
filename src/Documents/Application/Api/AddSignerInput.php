<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application\Api;

use Foresi\Documents\Domain\Signer;
use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

#[Operation(
    name: 'documents.signers.add',
    method: 'POST',
    path: '/api/v1/documents/{documentId}/signers',
    tool: 'foresi_add_document_signer',
    scope: 'documents:write',
    title: 'Add a signer to a document',
    description: 'Adds a signer to a document that is not completed, declined or cancelled.',
    status: 201,
    response: 'DocumentsSigner',
    staffOnly: true,
)]
final readonly class AddSignerInput
{
    public function __construct(
        #[Field('The id of the document.', format: 'uuid')]
        public string $documentId,
        #[Field('The name of the signer.', maxLength: Signer::MAX_NAME)]
        public string $name,
        #[Field('Signs for the firm (ours) or for the other party (theirs).', enum: ['ours', 'theirs'])]
        public string $side,
        #[Field('The signing order, 1 to 99; signers of one order sign in parallel. The order is shown, not enforced.')]
        public int $order = 1,
        #[Field('The e-mail of the signer.', maxLength: Signer::MAX_EMAIL)]
        public ?string $email = null,
        #[Field('The id of the contact person this signer is, from foresi_get_contact.', format: 'uuid')]
        public ?string $partyPersonId = null,
    ) {
    }
}
