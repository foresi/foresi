<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Banking\Domain\InboundChannel;
use App\Tenancy\Application\Schedule\OperatorTask;
use Psr\Log\LoggerInterface;

/**
 * Polls the operator mailbox: the oldest messages first, a batch per run, each
 * moved out of the inbox only once it is safely handled. A message whose
 * handling failed stays where it is and is fetched again on the next run; as a
 * stored mail is found again by its dedup key, a crash between storing and
 * moving costs nothing. The log names the UID, never the address.
 */
final readonly class PollBankInbox implements OperatorTask
{
    public const string NAME = 'bank-inbox';
    public const int BATCH = 50;

    public function __construct(
        private InboundMailbox $mailbox,
        private ReceiveInboundMail $receive,
        private LoggerInterface $logger,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function every(): string
    {
        return '5 minutes';
    }

    public function run(): void
    {
        foreach ($this->mailbox->fetch(self::BATCH) as $item) {
            try {
                match ($this->receive->receive($item->raw, InboundChannel::Imap)) {
                    ReceiveOutcome::Stored, ReceiveOutcome::Duplicate => $this->mailbox->markDone($item->uid),
                    ReceiveOutcome::UnknownRecipient => $this->mailbox->markUnknown($item->uid),
                    ReceiveOutcome::TooLarge, ReceiveOutcome::Unreadable => $this->mailbox->markRejected($item->uid),
                };
            } catch (\Throwable $failure) {
                $this->logger->error('The inbound message {uid} could not be handled; it stays in the inbox for the next poll.', ['uid' => $item->uid, 'exception' => $failure]);
            }
        }
    }
}
