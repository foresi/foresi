<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Application\Api;

use Foresi\Deals\Domain\LostReason;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A new lost reason over the API and MCP (ADR 0032).
 */
#[Operation(
    name: 'sales.lost_reasons.create',
    method: 'POST',
    path: '/api/v1/sales/lost-reasons',
    tool: 'foresi_create_lost_reason',
    scope: 'sales:write',
    title: 'Create a lost reason',
    description: 'Adds a reason a deal is lost for, at the end of the list. Its key, when given, is a lowercase identifier that never changes.',
    status: 201,
    response: 'SalesLostReason',
    staffOnly: true,
)]
final readonly class CreateLostReasonInput
{
    public function __construct(
        #[Field('The name, such as "Budget cut".', maxLength: LostReason::MAX_NAME)]
        public string $name,
        #[Field('A lowercase identifier, unique and never changed, such as "site_visit"; none when left out.', maxLength: 40)]
        public ?string $key = null,
    ) {
    }
}
