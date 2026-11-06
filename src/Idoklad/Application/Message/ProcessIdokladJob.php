<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application\Message;

use App\Tenancy\Application\TenantScopedMessage;

/**
 * Does one pull, check or run of the move from iDoklad, in the tenant the
 * message carries.
 */
final readonly class ProcessIdokladJob implements TenantScopedMessage
{
    public function __construct(public string $jobId)
    {
    }
}
