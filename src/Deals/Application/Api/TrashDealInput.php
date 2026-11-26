<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A deal put in the trash over the API and MCP (ADR 0032). Doing it twice
 * changes nothing.
 */
#[Operation(
    name: 'sales.deals.trash',
    method: 'POST',
    path: '/api/v1/sales/deals/{dealId}/trash',
    tool: 'foresi_trash_deal',
    scope: 'sales:write',
    title: 'Put a deal in the trash',
    description: 'Puts a deal in the trash: it leaves the lists and keeps everything, its lines and links included; restore it with foresi_restore_deal. Trashing it again changes nothing.',
    destructive: true,
    response: 'SalesDeal',
    staffOnly: true,
)]
final readonly class TrashDealInput
{
    public function __construct(
        #[Field('The id of the deal.', format: 'uuid')]
        public string $dealId,
    ) {
    }
}
