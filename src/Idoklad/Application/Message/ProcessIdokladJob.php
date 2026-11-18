<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Idoklad\Application\Message;

/**
 * Does one pull, check or run of the move from iDoklad.
 */
final readonly class ProcessIdokladJob
{
    public function __construct(public string $jobId)
    {
    }
}
