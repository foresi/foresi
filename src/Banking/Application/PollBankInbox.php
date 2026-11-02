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
 *
 * The poller never stalls: a message that failed MAX_FAILURES times is moved to
 * Rejected, and one that cannot even be moved there is left out of every later
 * batch, so a run of bad messages at the head of the inbox never keeps the
 * good ones behind it from being read.
 */
final readonly class PollBankInbox implements OperatorTask
{
    public const string NAME = 'bank-inbox';
    public const int BATCH = 50;
    public const int MAX_FAILURES = 5;

    public function __construct(
        private InboundMailbox $mailbox,
        private ReceiveInboundMail $receive,
        private LoggerInterface $logger,
        private PollFailures $failures,
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
        // Given up on and still in the inbox: moving them to Rejected failed as well.
        $stuck = $this->failures->atLeast(self::MAX_FAILURES);
        foreach ($this->mailbox->fetch(self::BATCH, $stuck) as $item) {
            try {
                match ($this->receive->receive($item->raw, InboundChannel::Imap)) {
                    ReceiveOutcome::Stored, ReceiveOutcome::Duplicate => $this->mailbox->markDone($item->uid),
                    ReceiveOutcome::UnknownRecipient => $this->mailbox->markUnknown($item->uid),
                    ReceiveOutcome::TooLarge, ReceiveOutcome::Unreadable => $this->mailbox->markRejected($item->uid),
                };
                $this->failures->clear($item->uid);
            } catch (\Throwable $failure) {
                $this->failed($item->uid, $failure);
            }
        }
    }

    private function failed(string $uid, \Throwable $failure): void
    {
        $count = $this->failures->failed($uid);
        if ($count < self::MAX_FAILURES) {
            $this->logger->error('The inbound message {uid} could not be handled ({count} of {max}); it stays in the inbox for the next poll.', ['uid' => $uid, 'count' => $count, 'max' => self::MAX_FAILURES, 'exception' => $failure]);

            return;
        }
        try {
            $this->mailbox->markRejected($uid);
            $this->failures->clear($uid);
            $this->logger->error('The inbound message {uid} failed {count} times and was moved to Rejected.', ['uid' => $uid, 'count' => $count, 'exception' => $failure]);
        } catch (\Throwable $unmovable) {
            $this->logger->error('The inbound message {uid} failed {count} times and cannot be moved; it stays in the inbox and is left out of every poll.', ['uid' => $uid, 'count' => $count, 'exception' => $unmovable]);
        }
    }
}
