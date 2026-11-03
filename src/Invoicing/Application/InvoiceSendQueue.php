<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * Port: hands a stored send to the worker. Called after the send is committed. A
 * failure to hand it over leaves the send queued, where RequeueStuckSends finds
 * it; the outcome of a delivery is recorded on the send, never thrown here.
 */
interface InvoiceSendQueue
{
    public function deliver(string $sendId): void;
}
