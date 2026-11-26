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
 * A stage renamed over the API and MCP (ADR 0032).
 */
#[Operation(
    name: 'sales.stages.update',
    method: 'PATCH',
    path: '/api/v1/sales/stages/{stageId}',
    tool: 'foresi_update_deal_stage',
    scope: 'sales:write',
    title: 'Rename a stage',
    description: 'Renames a stage. One that came with Foresi may be given null to show under its own name in each person\'s language again.',
    response: 'SalesStage',
    staffOnly: true,
)]
final readonly class UpdateDealStageInput
{
    public function __construct(
        #[Field('The id of the stage.', format: 'uuid')]
        public string $stageId,
        #[Field('The name; null for one that came with Foresi to show its own name again.', maxLength: Stage::MAX_NAME)]
        public ?string $name,
    ) {
    }
}
