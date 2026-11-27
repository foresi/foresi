<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * The stages put in a new order over the API and MCP (ADR 0032).
 */
#[Operation(
    name: 'deals.stages.reorder',
    method: 'POST',
    path: '/api/v1/deal-stages/reorder',
    tool: 'foresi_reorder_deal_stages',
    scope: 'deals:write',
    title: 'Reorder the stages',
    description: 'Puts the stages deals go through in the order given. Name every stage that is not archived once (foresi_list_deal_stages).',
    response: 'SalesStageList',
    staffOnly: true,
)]
final readonly class ReorderDealStagesInput
{
    /**
     * @param list<mixed> $stageIds
     */
    public function __construct(
        #[Field('The ids of all the stages not archived, in their new order.', items: 'string')]
        public array $stageIds,
    ) {
    }
}
