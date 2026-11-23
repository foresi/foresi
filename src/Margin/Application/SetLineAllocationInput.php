<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Foresi\Margin\Domain\LineAllocation;
use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * How the cost of one line of a received document is split among the firm
 * itself and its clients, or no split at all.
 */
#[Operation(
    name: 'margin.line_allocations.set',
    method: 'PUT',
    path: '/api/v1/margin/documents/{documentId}/lines/{lineKey}/allocation',
    tool: 'foresi_set_line_allocation',
    scope: 'margin:write',
    title: 'Split the cost of a line among clients',
    description: 'Says who bears the cost of one line of a recorded received document, and replaces what was said before. kind "whole" gives the line to one recipient; "percent" splits it by two or more percents that add up to exactly 100 (up to two decimals); "fixed" gives amounts in CZK to some recipients and the rest to the one share marked rest, never more than the line has. '
        .'A recipient is "own" (the firm itself) or the id of a contact, a client (find it with foresi_search_contacts); each recipient appears once. The shares are counted at once, in whole hellers of the base without VAT in CZK; a line that no one claims shows as unassigned. kind null clears the mapping of the line, so the mapping of the document, if any, applies again. '
        .'The line key is the `key` of a line that foresi_get_document_allocation returns; a document without lines has one implicit line per VAT rate, keyed "rate:21". Answers the document with its lines and shares.',
    response: 'MarginDocumentAllocation',
    staffOnly: true,
)]
final readonly class SetLineAllocationInput
{
    /**
     * @param list<mixed>|null $shares
     */
    public function __construct(
        #[Field('The id of the received document.', format: 'uuid')]
        public string $documentId,
        #[Field('The key of the line, as foresi_get_document_allocation returns it, such as the id of a line or "rate:21" for a document without lines.', maxLength: LineAllocation::MAX_KEY)]
        public string $lineKey,
        #[Field('How the cost is split: whole (one recipient), percent (percents adding up to 100) or fixed (amounts in CZK and one share that takes the rest); null clears the mapping of the line.', enum: ['whole', 'percent', 'fixed'])]
        public ?string $kind,
        #[Field('The recipients in order, objects of recipient ("own" or a contact id), percent (a decimal string such as "33.33", for percent), amount (a decimal in CZK such as "800.00", for fixed) and rest (true on the one fixed share that takes the rest). Left out when kind is null.', items: Field::OBJECTS)]
        public ?array $shares = null,
    ) {
    }
}
