<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Foresi\Activities\Contract\LinkableRecords;
use Foresi\Inbox\Application\Api\CreateContactFromItemInput;
use Foresi\Inbox\Application\Api\DiscardItemInput;
use Foresi\Inbox\Application\Api\LinkItemInput;
use Foresi\Inbox\Application\Api\MarkItemDoneInput;
use Foresi\Inbox\Application\Api\MatchItemAgainInput;
use Foresi\Inbox\Application\Api\MatchItemInput;
use Foresi\Inbox\Application\Api\ReopenItemInput;
use Foresi\Inbox\Application\Api\RestoreItemInput;
use Foresi\Inbox\Application\Api\TrashItemInput;
use Foresi\Inbox\Contract\Event\InboxItemDiscarded;
use Foresi\Inbox\Contract\Event\InboxItemDone;
use Foresi\Inbox\Contract\Event\InboxItemLinked;
use Foresi\Inbox\Contract\Event\InboxItemReopened;
use Foresi\Inbox\Contract\Event\InboxSuggestionRejected;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Domain\ItemOutput;
use Foresi\Inbox\Domain\ItemState;
use Foresi\Inbox\Domain\MatchBasis;
use Foresi\Inbox\Domain\Suggestion;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Mail\Contract\MailMessageView;
use Foresi\Mail\Contract\MessageRetention;
use Foresi\Mail\Contract\Messages;
use Foresi\Mail\Contract\SenderCheck;
use Foresi\Party\Contract\ContactCreation;
use Foresi\Party\Contract\NewContact;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Party\Contract\PartySummary;
use Foresi\Shared\Contract\Event\DomainEventPayload;
use Foresi\Shared\Contract\Event\EventRecorder;
use Foresi\Shared\Contract\Refused;
use Foresi\Shared\Domain\Id;

/**
 * What a person does with an item of their own inbox, one transaction each,
 * audited through the item:
 *
 * - match it to a contact by hand, or to none, or again by its sender as on
 *   arrival; create a contact from the sender and match the item to it. An
 *   e-mail activity made before gets the new contact and its company too, and
 *   keeps the ones it had;
 * - mark it done, discard it or reopen it. A discarded item without an output
 *   gives its mail back to Mail, which removes it 30 days later; leaving the
 *   discarded state keeps the mail again. Discarding rejects the suggestions
 *   on it that wait for a decision; marking it done asks what becomes of them
 *   (open_suggestions): rejected, or kept open on the done item;
 * - link it to a record, which becomes an output and a link of the item's one
 *   e-mail activity (EmailActivity);
 * - put it in the trash and restore it.
 *
 * Another person's item is not_found, as is one in the trash for everything but
 * trash and restore.
 */
final readonly class ItemWrites
{
    public const int DISCARD_DAYS = 30;

    public function __construct(
        private InboxAccess $access,
        private ItemStore $store,
        private InboxTransaction $transaction,
        private EventRecorder $events,
        private InstanceClock $clock,
        private PartyDirectory $contacts,
        private ContactCreation $creation,
        private SenderMatching $matching,
        private Messages $messages,
        private MessageRetention $retention,
        private LinkableRecords $records,
        private EmailActivity $emailActivity,
        private SuggestionStore $suggestions,
    ) {
    }

    /**
     * @throws InboxRefused not_found, contact_unknown, contact_archived, forbidden
     */
    public function match(MatchItemInput $input): InboxItem
    {
        return $this->transaction->run(function () use ($input): InboxItem {
            $item = $this->access->own($input->itemId);
            if (null === $input->contactId) {
                $item->unmatch();

                return $item;
            }
            $contact = Id::isValid($input->contactId) ? $this->contacts->find($input->contactId) : null;
            if (!$contact instanceof PartySummary) {
                throw InboxRefused::invalid('contact_unknown', 'contactId', 'There is no contact with this id. Find it with foresi_search_contacts.');
            }
            if ($contact->archived) {
                throw InboxRefused::invalid('contact_archived', 'contactId', 'The contact is archived; restore it with foresi_restore_contact first.');
            }
            $this->matchTo($item, $contact);

            return $item;
        });
    }

    /**
     * Matches the item by its sender, as on arrival (SenderMatching); a forward
     * by the original sender it names, never by domain.
     *
     * @throws InboxRefused not_found, forbidden
     */
    public function matchAgain(MatchItemAgainInput $input): InboxItem
    {
        return $this->transaction->run(function () use ($input): InboxItem {
            $item = $this->access->own($input->itemId);
            $message = $this->messages->find($item->mailMessageId());
            $match = match (true) {
                $item->isForwarded() => $this->matching->match($item->originalFrom(), false),
                $message instanceof MailMessageView => $this->matching->match($message->from, SenderCheck::VERIFIED === $message->verdict),
                default => SenderMatch::none(),
            };
            if (null !== $match->contactId && $match->basis instanceof MatchBasis) {
                $item->match($match->contactId, $match->companyId, $match->basis);
                $this->emailActivity->linkContacts($item);
            } else {
                $item->unmatch();
            }

            return $item;
        });
    }

    /**
     * Creates the contact through the address book, whose refusals come
     * unchanged (a duplicate with the ids it matched), and matches the item to it.
     *
     * @throws Refused the address book's refusal; InboxRefused not_found, forbidden
     */
    public function createContact(CreateContactFromItemInput $input): InboxItem
    {
        return $this->transaction->run(function () use ($input): InboxItem {
            $item = $this->access->own($input->itemId);
            $id = $this->creation->create(new NewContact(
                $input->kind,
                $input->displayName,
                $input->street,
                $input->city,
                $input->postalCode,
                $input->countryCode,
                $input->registrationNumber,
                $input->vatId,
                $input->email ?? $this->senderOf($item),
                $input->phone,
            ));
            // A new contact is linked to no company yet.
            $item->match($id, null, MatchBasis::Manual);
            $this->emailActivity->linkContacts($item);

            return $item;
        });
    }

    /**
     * @throws InboxRefused already_done, open_suggestions, invalid_input, not_found, forbidden
     */
    public function done(MarkItemDoneInput $input): InboxItem
    {
        if (null !== $input->suggestions && !\in_array($input->suggestions, MarkItemDoneInput::SUGGESTIONS, true)) {
            throw InboxRefused::invalidInput('suggestions', 'This is reject or keep.');
        }

        return $this->move($input->itemId, ItemState::Done, static fn (string $id): InboxItemDone => new InboxItemDone($id), function (InboxItem $item, string $userId, \DateTimeImmutable $now) use ($input): void {
            $open = $this->openSuggestions($item);
            if ([] === $open || MarkItemDoneInput::KEEP === $input->suggestions) {
                return;
            }
            if (null === $input->suggestions) {
                throw InboxRefused::openSuggestions(\count($open));
            }
            $this->reject($open, $userId, $now);
        });
    }

    /**
     * @throws InboxRefused already_discarded, not_found, forbidden
     */
    public function discard(DiscardItemInput $input): InboxItem
    {
        return $this->move($input->itemId, ItemState::Discarded, static fn (string $id): InboxItemDiscarded => new InboxItemDiscarded($id), function (InboxItem $item, string $userId, \DateTimeImmutable $now): void {
            $this->reject($this->openSuggestions($item), $userId, $now);
        });
    }

    /**
     * @throws InboxRefused already_new, not_found, forbidden
     */
    public function reopen(ReopenItemInput $input): InboxItem
    {
        return $this->move($input->itemId, ItemState::New, static fn (string $id): InboxItemReopened => new InboxItemReopened($id));
    }

    /**
     * @throws InboxRefused kind_unknown, record_not_linkable, already_linked, not_found, forbidden
     * @throws Refused      when Activities refuses the e-mail activity, such as type_unknown while the type email is archived
     */
    public function link(LinkItemInput $input): InboxItem
    {
        $userId = $this->access->me();
        try {
            return $this->linked($input, $userId);
        } catch (UniqueConstraintViolationException) {
            // The same link made at the same moment by another request.
            throw InboxRefused::conflict('already_linked', 'The item is linked to this record already.');
        }
    }

    /**
     * @throws InboxRefused
     * @throws Refused
     */
    private function linked(LinkItemInput $input, string $userId): InboxItem
    {
        return $this->transaction->run(function () use ($input, $userId): InboxItem {
            $item = $this->access->own($input->itemId);
            $kinds = $this->records->kinds();
            if (!\in_array($input->kind, $kinds, true)) {
                throw InboxRefused::invalid('kind_unknown', 'kind', \sprintf('No module links e-mails to records of this kind; the kinds offered are: %s.', [] === $kinds ? 'none' : implode(', ', $kinds)));
            }
            if (!$this->records->canLink($input->kind, $input->id)) {
                throw InboxRefused::invalid('record_not_linkable', 'id', 'There is no record of this kind with this id that you may link to.');
            }
            foreach ($this->store->outputs($item) as $output) {
                if ($output->kind() === $input->kind && $output->recordId() === $input->id) {
                    throw InboxRefused::conflict('already_linked', 'The item is linked to this record already.');
                }
            }
            if (ItemState::Discarded === $item->state()) {
                // Something came of it now: the mail is kept.
                $this->retention->keep($item->mailMessageId());
            }
            $this->store->add(new ItemOutput($item, $input->kind, $input->id, $this->now(), $userId));
            $this->emailActivity->link($item, $input->kind, $input->id);
            $this->events->record(new InboxItemLinked($item->id(), $input->kind));

            return $item;
        });
    }

    /**
     * @throws InboxRefused not_found, forbidden
     */
    public function trash(TrashItemInput $input): InboxItem
    {
        $userId = $this->access->me();

        return $this->transaction->run(function () use ($input, $userId): InboxItem {
            $item = $this->access->ownOrTrashed($input->itemId);
            $item->trash($userId, $this->now());

            return $item;
        });
    }

    /**
     * @throws InboxRefused not_found, forbidden
     */
    public function restore(RestoreItemInput $input): InboxItem
    {
        return $this->transaction->run(function () use ($input): InboxItem {
            $item = $this->access->ownOrTrashed($input->itemId);
            $item->restore();

            return $item;
        });
    }

    /**
     * @param \Closure(string): DomainEventPayload                         $event
     * @param (\Closure(InboxItem, string, \DateTimeImmutable): void)|null $then  what else the move does, in its transaction
     *
     * @throws InboxRefused
     */
    private function move(string $itemId, ItemState $to, \Closure $event, ?\Closure $then = null): InboxItem
    {
        $userId = $this->access->me();

        return $this->transaction->run(function () use ($itemId, $to, $event, $then, $userId): InboxItem {
            $item = $this->access->own($itemId);
            // The item first, then its suggestions (reject()): the order every decision locks in.
            $this->store->lock($item);
            $from = $item->state();
            $now = $this->now();
            if (!$item->changeState($to, $userId, $now)) {
                throw InboxRefused::already($to);
            }
            if ($then instanceof \Closure) {
                $then($item, $userId, $now);
            }
            if (ItemState::Discarded === $from) {
                $this->retention->keep($item->mailMessageId());
            }
            if (ItemState::Discarded === $to && [] === $this->store->outputs($item)) {
                $this->retention->release($item->mailMessageId(), $now->modify(\sprintf('+%d days', self::DISCARD_DAYS)));
            }
            $this->events->record($event($item->id()));

            return $item;
        });
    }

    /**
     * @return list<Suggestion> the item's suggestions that wait for a decision
     */
    private function openSuggestions(InboxItem $item): array
    {
        return array_values(array_filter($this->suggestions->forItem($item), static fn (Suggestion $suggestion): bool => $suggestion->isOpen()));
    }

    /**
     * Rejects the suggestions without a reason, each locked first (by id, after the item) so
     * that a step running at the same moment is not overwritten.
     *
     * @param list<Suggestion> $open
     */
    private function reject(array $open, string $userId, \DateTimeImmutable $now): void
    {
        usort($open, static fn (Suggestion $a, Suggestion $b): int => strcmp($a->id(), $b->id()));
        foreach ($open as $suggestion) {
            $this->suggestions->lockForUpdate($suggestion);
            if ($suggestion->isOpen()) {
                $suggestion->reject($userId, null, $now);
                $this->events->record(new InboxSuggestionRejected($suggestion->id(), $suggestion->itemId()));
            }
        }
    }

    private function matchTo(InboxItem $item, PartySummary $contact): void
    {
        $item->match($contact->id, $this->matching->companyOf($contact), MatchBasis::Manual);
        $this->emailActivity->linkContacts($item);
    }

    /**
     * The address a new contact gets: the original sender of a forward, else the sender.
     */
    private function senderOf(InboxItem $item): string
    {
        return $item->originalFrom() ?? (string) $this->messages->find($item->mailMessageId())?->from;
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->clock()->now());
    }
}
