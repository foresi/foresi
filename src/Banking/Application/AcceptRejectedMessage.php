<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Banking\Domain\InboundMessage;
use App\Banking\Domain\InboundStatus;

/**
 * "Record anyway" on a message rejected because its sender could not be trusted,
 * after a person checked the payment in the bank: its movements are recorded as
 * unverified, accepted by a user, and are never paired by themselves. Such a
 * message may be forged, so only the owner records it, and the audit log keeps
 * who did and when, on the message. A message about an account that is not the
 * firm's is refused whoever asks: it is either someone else's money or the wrong
 * address.
 */
final readonly class AcceptRejectedMessage
{
    public function __construct(private InboundMessages $messages, private ProcessMessage $process, private Ownership $ownership)
    {
    }

    /**
     * @return InboundStatus where the message stands afterwards: Rejected when its account turns out not to be the firm's
     *
     * @throws AcceptRefused         when the user is not the owner, or the message is not rejected for its trust; nothing is stored
     * @throws UnknownInboundMessage also for a message of another tenant
     */
    public function accept(string $messageId): InboundStatus
    {
        if (!$this->ownership->isOwner()) {
            throw new AcceptRefused(AcceptRefused::NOT_OWNER);
        }
        $message = $this->messages->find($messageId);
        if (!$message instanceof InboundMessage) {
            throw new UnknownInboundMessage();
        }
        if (InboundStatus::Rejected !== $message->status()) {
            throw new AcceptRefused(AcceptRefused::NOT_REJECTED);
        }
        if (ProcessMessage::UNTRUSTED !== $message->failure()) {
            throw new AcceptRefused(AcceptRefused::ACCOUNT_MISMATCH);
        }

        return $this->process->processAccepted($messageId);
    }
}
