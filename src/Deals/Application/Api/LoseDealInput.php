<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Application\Api;

use Foresi\Deals\Domain\Deal;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * An open deal lost, over the API and MCP (ADR 0032).
 */
#[Operation(
    name: 'deals.lose',
    method: 'POST',
    path: '/api/v1/deals/{dealId}/lose',
    tool: 'foresi_lose_deal',
    scope: 'deals:write',
    title: 'Mark a deal lost',
    description: 'Marks an open deal lost, with a reason from the list (foresi_list_deal_stages lists the lost reasons) and a note, both optional. A lost deal is reopened with foresi_reopen_deal.',
    response: 'SalesDeal',
    staffOnly: true,
)]
final readonly class LoseDealInput
{
    public function __construct(
        #[Field('The id of the deal.', format: 'uuid')]
        public string $dealId,
        #[Field('Why it was lost: the id of a lost reason.', format: 'uuid')]
        public ?string $lostReasonId = null,
        #[Field('What happened, in a sentence or two.', maxLength: Deal::MAX_LOST_NOTE)]
        public ?string $note = null,
    ) {
    }
}
