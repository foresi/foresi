<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Banking\Domain\InboundMessage;
use App\Banking\Domain\InboundStatus;
use App\Invoicing\Application\ConflictRetry;
use App\Party\Application\TransactionalWork;
use Psr\Clock\ClockInterface;

/**
 * "Dismiss" on a message nobody will record: one about an account that is not
 * the firm's, a failure no parser will read, a mail that is no bank's, or one the
 * queue gave up on. It stops asking for attention and is never processed again;
 * the raw message stays stored as it came, and the audit log says who set it
 * aside and when, as no movement records it.
 */
final readonly class DismissInboundMessage
{
    /** @var non-empty-list<InboundStatus> */
    public const array FROM = [InboundStatus::Failed, InboundStatus::Rejected, InboundStatus::NotBankMail, InboundStatus::Received];

    public function __construct(
        private InboundMessages $messages,
        private TransactionalWork $work,
        private ConflictRetry $retry,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return bool false when the message stands elsewhere, processed or dismissed already, or still in the queue; nothing is stored
     *
     * @throws UnknownInboundMessage also for a message of another tenant
     */
    public function dismiss(string $messageId): bool
    {
        if (!$this->messages->find($messageId) instanceof InboundMessage) {
            throw new UnknownInboundMessage();
        }
        $stuckBefore = BankScreen::stuckBefore($this->clock);

        return $this->retry->run(fn (): bool => $this->work->run(fn (): bool => $this->messages->dismiss($messageId, self::FROM, $stuckBefore) instanceof InboundStatus));
    }
}
