<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A lost reason archived over the API and MCP (ADR 0032). Doing it twice changes
 * nothing.
 */
#[Operation(
    name: 'sales.lost_reasons.archive',
    method: 'POST',
    path: '/api/v1/sales/lost-reasons/{reasonId}/archive',
    tool: 'foresi_archive_lost_reason',
    scope: 'sales:write',
    title: 'Archive a lost reason',
    description: 'Archives a lost reason: it is no longer offered, and the deals lost for it keep it.',
    response: 'SalesLostReason',
    staffOnly: true,
)]
final readonly class ArchiveLostReasonInput
{
    public function __construct(
        #[Field('The id of the lost reason.', format: 'uuid')]
        public string $reasonId,
    ) {
    }
}
