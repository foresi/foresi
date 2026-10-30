<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Carries the id of the tenant a message was dispatched in across the queue.
 */
final readonly class TenantStamp implements StampInterface
{
    public function __construct(public string $tenantId)
    {
    }
}
