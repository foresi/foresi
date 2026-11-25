<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Operation;

use Foresi\Shared\Contract\Operation\Operation;

#[Operation(name: 'things.issue', method: 'POST', path: '/api/v1/things/{thingId}/issue', tool: 'foresi_issue_thing', scope: 'things:write', title: 'Issue a thing', description: 'Issues a thing for good.', destructive: true, needsConfirmation: true)]
final readonly class ConfirmedThingInput
{
    public function __construct(public string $thingId)
    {
    }
}
