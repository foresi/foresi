<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Console;

use Foresi\Invoicing\Application\ConflictRetry;
use Foresi\Invoicing\Application\InvoiceSends;
use Foresi\Invoicing\Application\PublicDocuments;
use Foresi\Party\Application\TransactionalWork;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ends both links of one send for good, for the operator: a delivered invoice
 * cannot go to the trash, so a link sent to a wrong address is ended here. The
 * change goes through the send, so the audit log records it.
 */
#[AsCommand(
    name: 'app:invoice:revoke-link',
    description: 'End the links of a sent document for good',
    help: <<<'HELP'
    Ends both links of one send of a document for good. Run it by hand when a link went to a wrong
    address, because a delivered invoice cannot go to the trash. The change goes through the send, so
    the audit log records it. Sending the document again gives a new link. It is safe to repeat. It
    exits with 1 when no send has the id.

      bin/console app:invoice:revoke-link <send-id>
    HELP,
)]
final readonly class RevokeLinkCommand
{
    public function __construct(
        private PublicDocuments $documents,
        private InvoiceSends $sends,
        private TransactionalWork $work,
        private ConflictRetry $retry,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('The id of the send, as the send log shows it', name: 'sendId')] string $sendId): int
    {
        if (!$this->documents->exists($sendId)) {
            $io->error(\sprintf('No send has the id %s.', $sendId));

            return Command::FAILURE;
        }
        $this->retry->run(fn (): null => $this->work->run(function () use ($sendId): null {
            $this->sends->lock($sendId)->revoke(\DateTimeImmutable::createFromInterface($this->clock->now()));

            return null;
        }));
        $io->success(\sprintf('The links of send %s are ended. Sending the document again gives a new link.', $sendId));

        return Command::SUCCESS;
    }
}
