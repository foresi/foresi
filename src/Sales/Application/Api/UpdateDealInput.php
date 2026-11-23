<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application\Api;

use Foresi\Sales\Domain\Deal;
use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Omitted;
use Foresi\Shared\Application\Api\Operation;

/**
 * A deal changed over the API and MCP (ADR 0032): the fields given change,
 * the rest stay.
 */
#[Operation(
    name: 'sales.deals.update',
    method: 'PATCH',
    path: '/api/v1/sales/deals/{dealId}',
    tool: 'foresi_update_deal',
    scope: 'sales:write',
    title: 'Update a deal',
    description: 'Changes the fields given of a deal and keeps the rest: its title, description, owner or contact persons (the whole list, in order; an empty list removes them all). Its stage, status and lines change with their own tools.',
    response: 'SalesDeal',
    staffOnly: true,
)]
final readonly class UpdateDealInput
{
    /**
     * @param list<mixed>|Omitted $contactPersonIds
     */
    public function __construct(
        #[Field('The id of the deal.', format: 'uuid')]
        public string $dealId,
        #[Field('What is being sold.', maxLength: Deal::MAX_TITLE)]
        public string|Omitted $title = new Omitted(),
        #[Field('Notes on the deal, in Markdown.', maxLength: Deal::MAX_DESCRIPTION)]
        public string|Omitted $description = new Omitted(),
        #[Field('The owner or member who works on it.', format: 'uuid')]
        public string|Omitted $ownerUserId = new Omitted(),
        #[Field('The ids of the client\'s contact persons, in order; they replace the ones before.', items: 'string')]
        public array|Omitted $contactPersonIds = new Omitted(),
    ) {
    }
}
