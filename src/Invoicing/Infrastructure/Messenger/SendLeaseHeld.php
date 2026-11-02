<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Messenger;

/**
 * Another worker holds the claim on the send. Not a failure: the message comes
 * back when the lease ends, without using one of its retries.
 */
final class SendLeaseHeld extends \RuntimeException
{
    /**
     * @param int $delayMilliseconds until the lease ends, and a little after
     */
    public function __construct(public readonly int $delayMilliseconds)
    {
        parent::__construct('Another worker is delivering this send.');
    }
}
