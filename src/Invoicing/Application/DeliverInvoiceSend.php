<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Tenancy\Application\TenantScopedMessage;

/**
 * Delivers one stored send in the tenant the message carries. It holds only the
 * id: the link is derived when the mail leaves, so no link sits in the queue.
 */
final readonly class DeliverInvoiceSend implements TenantScopedMessage
{
    public function __construct(public string $sendId)
    {
    }
}
