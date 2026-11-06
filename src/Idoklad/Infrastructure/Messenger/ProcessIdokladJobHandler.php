<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Infrastructure\Messenger;

use App\Idoklad\Application\IdokladJobRunner;
use App\Idoklad\Application\Message\ProcessIdokladJob;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Does a pull, check or run of the move from iDoklad in the tenant of the message. */
#[AsMessageHandler]
final readonly class ProcessIdokladJobHandler
{
    public function __construct(private IdokladJobRunner $runner)
    {
    }

    public function __invoke(ProcessIdokladJob $message): void
    {
        $this->runner->process($message->jobId);
    }
}
