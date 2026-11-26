<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application\Api;

use Foresi\Documents\Domain\Signer;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

#[Operation(
    name: 'documents.signers.update',
    method: 'PATCH',
    path: '/api/v1/documents/{documentId}/signers/{signerId}',
    tool: 'foresi_update_document_signer',
    scope: 'documents:write',
    title: 'Change a signer of a document',
    description: 'Changes a pending signer of a document that is not completed, declined or cancelled; all fields are replaced.',
    response: 'DocumentsSigner',
    staffOnly: true,
)]
final readonly class UpdateSignerInput
{
    public function __construct(
        #[Field('The id of the document.', format: 'uuid')]
        public string $documentId,
        #[Field('The id of the signer.', format: 'uuid')]
        public string $signerId,
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
