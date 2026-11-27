<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A stage archived over the API and MCP (ADR 0032). Doing it twice changes
 * nothing.
 */
#[Operation(
    name: 'deals.stages.archive',
    method: 'POST',
    path: '/api/v1/deal-stages/{stageId}/archive',
    tool: 'foresi_archive_deal_stage',
    scope: 'deals:write',
    title: 'Archive a stage',
    description: 'Archives a stage: it is no longer offered and leaves the order. A stage that still holds open deals is refused: move them first.',
    response: 'SalesStage',
    staffOnly: true,
)]
final readonly class ArchiveDealStageInput
{
    public function __construct(
        #[Field('The id of the stage.', format: 'uuid')]
        public string $stageId,
    ) {
    }
}
