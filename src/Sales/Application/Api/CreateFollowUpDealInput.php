<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application\Api;

use Foresi\Sales\Domain\Deal;
use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * A follow-up deal over the API and MCP (ADR 0032): a new deal for the same
 * client that follows from the one before.
 */
#[Operation(
    name: 'sales.deals.create_follow_up',
    method: 'POST',
    path: '/api/v1/sales/deals/{dealId}/follow-ups',
    tool: 'foresi_create_follow_up_deal',
    scope: 'sales:write',
    title: 'Create a follow-up deal',
    description: 'Opens a new deal for the same client and contact persons that follows from this one, such as a monthly service after a one-off audit. The lines named are copied as they are on this deal; the others are left out. It starts at the first stage and is owned by the caller unless others are named.',
    status: 201,
    response: 'SalesDeal',
    staffOnly: true,
)]
final readonly class CreateFollowUpDealInput
{
    /**
     * @param list<mixed> $lineIds
     */
    public function __construct(
        #[Field('The id of the deal it follows from.', format: 'uuid')]
        public string $dealId,
        #[Field('What the new deal sells.', maxLength: Deal::MAX_TITLE)]
        public string $title,
        #[Field('The ids of the lines of this deal to copy onto the new one.', items: 'string')]
        public array $lineIds = [],
        #[Field('The stage the new deal starts at; the first stage when left out.', format: 'uuid')]
        public ?string $stageId = null,
        #[Field('The owner or member who works on it; the caller when left out.', format: 'uuid')]
        public ?string $ownerUserId = null,
    ) {
    }
}
