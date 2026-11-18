<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

/**
 * Delivers one stored send. It holds only the
 * id: the link is derived when the mail leaves, so no link sits in the queue.
 */
final readonly class DeliverInvoiceSend
{
    public function __construct(public string $sendId)
    {
    }
}
