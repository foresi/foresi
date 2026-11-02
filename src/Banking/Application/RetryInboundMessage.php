<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Banking\Domain\InboundStatus;

/**
 * "Try again" on a message: processes it now, as the queue would. Only a message
 * that failed, found no parser or still waits is processed; any other is left as
 * it stands, so pressing twice records nothing twice.
 */
final readonly class RetryInboundMessage
{
    public function __construct(private ProcessMessage $process)
    {
    }

    /**
     * @return InboundStatus where the message stands afterwards
     *
     * @throws UnknownInboundMessage also for a message of another tenant
     */
    public function retry(string $messageId): InboundStatus
    {
        return $this->process->process($messageId);
    }
}
