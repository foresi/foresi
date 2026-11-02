<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Console;

use App\Invoicing\Application\ConflictRetry;
use App\Invoicing\Application\InvoiceSends;
use App\Invoicing\Application\PublicDocuments;
use App\Party\Application\TransactionalWork;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ends both links of one send for good, for the operator: a delivered invoice
 * cannot go to the trash, so a link sent to a wrong address is ended here. The
 * change goes through the send of its own tenant, so the audit log records it.
 */
#[AsCommand(name: 'app:invoice:revoke-link', description: 'End the links of a sent document for good')]
final readonly class RevokeLinkCommand
{
    public function __construct(
        private PublicDocuments $documents,
        private InvoiceSends $sends,
        private TenantContext $context,
        private TransactionalWork $work,
        private ConflictRetry $retry,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('The id of the send, as the send log shows it', name: 'sendId')] string $sendId): int
    {
        $tenant = $this->documents->tenantOf($sendId);
        if (null === $tenant) {
            $io->error(\sprintf('No send has the id %s.', $sendId));

            return Command::FAILURE;
        }
        $this->context->switchTo(TenantId::fromString($tenant));
        try {
            $this->retry->run(fn (): null => $this->work->run(function () use ($sendId): null {
                $this->sends->lock($sendId)->revoke(\DateTimeImmutable::createFromInterface($this->clock->now()));

                return null;
            }));
        } finally {
            $this->context->reset();
        }
        $io->success(\sprintf('The links of send %s are ended. Sending the document again gives a new link.', $sendId));

        return Command::SUCCESS;
    }
}
