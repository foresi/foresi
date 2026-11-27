<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Application\Api;

use Foresi\Deals\Domain\Stage;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A new stage over the API and MCP (ADR 0032).
 */
#[Operation(
    name: 'deals.stages.create',
    method: 'POST',
    path: '/api/v1/deal-stages',
    tool: 'foresi_create_deal_stage',
    scope: 'deals:write',
    title: 'Create a stage',
    description: 'Adds a stage deals go through, at the end of the order; move it with foresi_reorder_deal_stages. Its key, when given, is a lowercase identifier that never changes.',
    status: 201,
    response: 'SalesStage',
    staffOnly: true,
)]
final readonly class CreateDealStageInput
{
    public function __construct(
        #[Field('The name, such as "Site visit".', maxLength: Stage::MAX_NAME)]
        public string $name,
        #[Field('A lowercase identifier, unique and never changed, such as "site_visit"; none when left out.', maxLength: 40)]
        public ?string $key = null,
    ) {
    }
}
