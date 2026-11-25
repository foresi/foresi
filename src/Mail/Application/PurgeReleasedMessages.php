<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Application;

use Foresi\Instance\Application\Schedule\OperatorTask;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Removes the messages a consumer released (MessageRetention) once their date has
 * passed, the oldest first, a batch per run. The row goes first and the file after
 * it, so a row that cannot be removed because another table still points at it keeps
 * its file, and is logged by id only (never an address) and tried again the next day.
 * A file already gone is no failure.
 */
final readonly class PurgeReleasedMessages implements OperatorTask
{
    public const string NAME = 'mail-purge';
    public const int BATCH = 200;

    public function __construct(
        private MailMessages $messages,
        private InboundFiles $files,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function every(): string
    {
        return '1 day';
    }

    public function run(): void
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        foreach ($this->messages->duePurge($now, self::BATCH) as $id) {
            if (!$this->messages->delete($id)) {
                $this->logger->warning('The released mail message {id} is still in use and was kept.', ['id' => $id]);

                continue;
            }
            $this->files->remove($id);
        }
    }
}
