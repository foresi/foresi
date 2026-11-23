<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application\Api;

use Foresi\Sales\Domain\LostReason;
use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * A lost reason renamed over the API and MCP (ADR 0032).
 */
#[Operation(
    name: 'sales.lost_reasons.update',
    method: 'PATCH',
    path: '/api/v1/sales/lost-reasons/{reasonId}',
    tool: 'foresi_update_lost_reason',
    scope: 'sales:write',
    title: 'Rename a lost reason',
    description: 'Renames a lost reason. One that came with Foresi may be given null to show under its own name in each person\'s language again.',
    response: 'SalesLostReason',
    staffOnly: true,
)]
final readonly class UpdateLostReasonInput
{
    public function __construct(
        #[Field('The id of the lost reason.', format: 'uuid')]
        public string $reasonId,
        #[Field('The name; null for one that came with Foresi to show its own name again.', maxLength: LostReason::MAX_NAME)]
        public ?string $name,
    ) {
    }
}
