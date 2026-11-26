<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application\Api;

use Foresi\Sales\Domain\Deal;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A new deal over the API and MCP (ADR 0032).
 */
#[Operation(
    name: 'sales.deals.create',
    method: 'POST',
    path: '/api/v1/sales/deals',
    tool: 'foresi_create_deal',
    scope: 'sales:write',
    title: 'Create a deal',
    description: 'Opens a deal with a client: what is being sold, at the first stage unless another is named (stages from foresi_list_deal_stages), owned by the caller unless another owner or member is named. The contact persons must be linked to the client in the address book (foresi_get_contact). An archived client takes no new deal. Add what it sells with foresi_add_deal_line.',
    status: 201,
    response: 'SalesDeal',
    staffOnly: true,
)]
final readonly class CreateDealInput
{
    /**
     * @param list<mixed> $contactPersonIds
     */
    public function __construct(
        #[Field('What is being sold, such as "Roof audit for the warehouse".', maxLength: Deal::MAX_TITLE)]
        public string $title,
        #[Field('The id of the client, a contact of the address book.', format: 'uuid')]
        public string $clientPartyId,
        #[Field('The stage it starts at; the first stage when left out.', format: 'uuid')]
        public ?string $stageId = null,
        #[Field('The owner or member who works on it; the caller when left out.', format: 'uuid')]
        public ?string $ownerUserId = null,
        #[Field('Notes on the deal, in Markdown.', maxLength: Deal::MAX_DESCRIPTION)]
        public string $description = '',
        #[Field('The ids of the client\'s contact persons the deal is talked through with, in order.', items: 'string')]
        public array $contactPersonIds = [],
    ) {
    }
}
