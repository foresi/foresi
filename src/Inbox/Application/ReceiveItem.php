<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

use Foresi\Identity\Application\UserRepository;
use Foresi\Identity\Contract\StaffCheck;
use Foresi\Identity\Domain\User;
use Foresi\Inbox\Contract\Event\InboxItemReceived;
use Foresi\Inbox\Domain\ForwardedSender;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Domain\MatchBasis;
use Foresi\Inbox\Domain\ThreadKey;
use Foresi\Mail\Contract\CrossPurposeDuplicates;
use Foresi\Mail\Contract\MailMessageView;
use Foresi\Mail\Contract\Messages;
use Foresi\Mail\Contract\NotAccepted;
use Foresi\Mail\Contract\SenderCheck;
use Foresi\Shared\Contract\Event\EventRecorder;
use Foresi\Shared\Domain\Id;

/**
 * Makes an inbox item of a mail Mail stored for a person's inbox address, in
 * the transaction that stored it (InboxPurpose):
 *
 * - a mail from the owner's own address with a forward block the parser knows
 *   is a forward, matched by the original sender the block names and never by
 *   domain, since nobody verified that sender; any other mail is matched by its
 *   sender, by domain only when the receiving server verified it;
 * - the mails of one conversation share a thread key;
 * - a mail that came under another purpose too, such as a bank notice that also
 *   reached the bank feed, names the first such purpose.
 *
 * A message already made into an item changes nothing, so the hand-over can be
 * repeated. A mail for an address without an owner, or of a person who no longer
 * works for the organisation, is not accepted: Mail drops it as it drops mail for
 * an unknown address. Records inbox.item.received with the item's id only.
 */
final readonly class ReceiveItem
{
    public function __construct(
        private Messages $messages,
        private CrossPurposeDuplicates $duplicates,
        private UserRepository $users,
        private StaffCheck $staff,
        private ForwardParser $forwards,
        private SenderMatching $matching,
        private ItemStore $store,
        private InboxTransaction $transaction,
        private EventRecorder $events,
    ) {
    }

    /**
     * @return string the id of the item, new or made before
     *
     * @throws NotAccepted when the message is unknown, has no owner, or the owner left
     */
    public function receive(string $messageId): string
    {
        $existing = $this->store->itemOfMessage($messageId);
        if ($existing instanceof InboxItem) {
            return $existing->id();
        }
        $message = $this->messages->find($messageId) ?? throw new NotAccepted('The inbox takes stored messages only.');
        $owner = null === $message->ownerUserId ? null : $this->users->findById($message->ownerUserId);
        if (!$owner instanceof User || !$this->staff->isStaffMember($owner->id())) {
            throw new NotAccepted('An inbox address belongs to a person of the organisation.');
        }

        $forward = $this->forwardOf($message, $owner);
        $match = $forward instanceof ForwardedSender
            ? $this->matching->match($forward->address, false)
            : $this->matching->match($message->from, SenderCheck::VERIFIED === $message->verdict);
        $id = Id::generate();
        $item = new InboxItem(
            $id,
            $message->id,
            $owner->id(),
            $message->receivedAt,
            $forward instanceof ForwardedSender,
            $forward?->address,
            ThreadKey::of($message->references, $message->inReplyTo, $message->messageIdHeader, $id),
            $this->duplicates->of($message->id)[0]['purpose'] ?? null,
        );
        if (null !== $match->contactId && $match->basis instanceof MatchBasis) {
            $item->match($match->contactId, $match->companyId, $match->basis);
        }

        return $this->transaction->run(function () use ($item): string {
            $this->store->add($item);
            $this->events->record(new InboxItemReceived($item->id()));

            return $item->id();
        });
    }

    /**
     * The original sender, when the owner forwarded the mail from their own
     * address; a forward block the parser does not know keeps the owner as sender.
     */
    private function forwardOf(MailMessageView $message, User $owner): ?ForwardedSender
    {
        // A user's e-mail address is kept trimmed and in lower case, and is ASCII.
        if (null === $message->from || strtolower(trim($message->from)) !== $owner->email()) {
            return null;
        }

        return $this->forwards->parse($this->messages->text($message->id));
    }
}
