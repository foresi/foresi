<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application;

use Foresi\Party\Contract\ContactPersonRefused;
use Foresi\Party\Contract\ContactPersonWrites;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Party\Contract\PartyPersonSummary;
use Foresi\Party\Contract\PartySummary;
use Foresi\Sales\Application\Api\ChangeDealStageInput;
use Foresi\Sales\Application\Api\CreateDealInput;
use Foresi\Sales\Application\Api\CreateFollowUpDealInput;
use Foresi\Sales\Application\Api\LoseDealInput;
use Foresi\Sales\Application\Api\ReopenDealInput;
use Foresi\Sales\Application\Api\RestoreDealInput;
use Foresi\Sales\Application\Api\TrashDealInput;
use Foresi\Sales\Application\Api\UpdateDealInput;
use Foresi\Sales\Application\Api\WinDealInput;
use Foresi\Sales\Contract\Event\DealCreated;
use Foresi\Sales\Contract\Event\DealLost;
use Foresi\Sales\Contract\Event\DealReopened;
use Foresi\Sales\Contract\Event\DealRestored;
use Foresi\Sales\Contract\Event\DealStageChanged;
use Foresi\Sales\Contract\Event\DealTrashed;
use Foresi\Sales\Contract\Event\DealUpdated;
use Foresi\Sales\Contract\Event\DealWon;
use Foresi\Sales\Contract\Field\DealExtension;
use Foresi\Sales\Domain\Deal;
use Foresi\Sales\Domain\DealContact;
use Foresi\Sales\Domain\DealLine;
use Foresi\Sales\Domain\DealLink;
use Foresi\Sales\Domain\DealLinkType;
use Foresi\Sales\Domain\LostReason;
use Foresi\Sales\Domain\Stage;
use Foresi\Shared\Application\Field\CustomFieldWrites;
use Foresi\Shared\Application\Field\FieldRefused;
use Foresi\Shared\Contract\Event\EventRecorder;
use Foresi\Shared\Contract\Operation\Omitted;

/**
 * The writes of a deal itself, from its screens, the API and MCP alike (ADR
 * 0032). Each runs in one unit of work with its sales.deal event, and only the
 * owners and members of the organisation write. The client is a contact of
 * the address book, not archived for a new deal or a deal moved to it; the
 * contact persons are linked to it; the owner is an owner or member. A deal in
 * the trash is not found, except to trash or restore it. The custom fields of a
 * deal (ADR 0045) come from its screens beside the input, which the API does not
 * carry yet: they are written in the same unit of work, and sales.deal.updated
 * names the keys that changed.
 */
final readonly class DealWrites
{
    public function __construct(
        private SalesAccess $access,
        private SalesStore $store,
        private SalesWork $work,
        private EventRecorder $events,
        private SalesClock $clock,
        private PartyDirectory $parties,
        private ContactPersonWrites $contactPersons,
        private LineProjects $projects,
        private CustomFieldWrites $customFields,
        private BillingPlanWrites $billingPlan,
    ) {
    }

    /**
     * A new deal; the person of the dialog, when one is given, is added to the client and
     * becomes the last of its contact persons.
     *
     * @param array<string, mixed>|null $customFields the custom fields by key, each required one with a value; null when the caller offers none
     *
     * @throws SalesRefused
     * @throws ContactPersonRefused nothing is stored
     * @throws FieldRefused         nothing is stored
     */
    public function create(CreateDealInput $input, ?ContactPersonDraft $newPerson = null, ?array $customFields = null): Deal
    {
        $caller = $this->access->denyUnlessStaff();

        return $this->work->run(function () use ($input, $caller, $newPerson, $customFields): Deal {
            $client = $this->newDealClient($input->clientPartyId);
            $persons = $this->persons($client->id, $input->contactPersonIds);
            $deal = SalesRefused::unless(fn (): Deal => new Deal(
                $input->title,
                $input->description,
                $client->id,
                $this->owner($input->ownerUserId ?? $caller),
                $this->startStage($input->stageId)->id(),
                $this->clock->now(),
            ));
            if ($newPerson instanceof ContactPersonDraft) {
                $persons = $this->withNewPerson($client->id, $persons, $newPerson);
            }
            $this->store->add($deal);
            $this->replaceContacts($deal, $persons);
            if (null !== $customFields) {
                $this->customFields->write(DealExtension::RECORD, $deal->id(), $customFields, true);
            }
            $this->events->record(new DealCreated($deal->id(), $deal->status()->value, $deal->stageId()));

            return $deal;
        });
    }

    /**
     * Changes the fields given. Another client takes the contact persons given in the same
     * call, checked against it, or none; a deal linked to other deals keeps its client, as a
     * link joins deals of one client only; a deal whose hourly line links a project of its
     * client keeps its client too, as a line links only a project of the deal's client or of
     * no client. The person of the dialog, when one is given, is
     * added to the client (the new one, when it changes) and becomes the last of the deal's
     * contact persons. The custom fields given are written too.
     *
     * @param array<string, mixed>|null $customFields the custom fields to change by key, null clearing one; null when the caller offers none
     *
     * @throws SalesRefused
     * @throws ContactPersonRefused nothing is stored
     * @throws FieldRefused         nothing is stored
     */
    public function update(UpdateDealInput $input, ?ContactPersonDraft $newPerson = null, ?array $customFields = null): Deal
    {
        $this->access->denyUnlessStaff();

        return $this->work->run(function () use ($input, $newPerson, $customFields): Deal {
            $deal = $this->deal($input->dealId);
            $now = $this->clock->now();
            SalesRefused::unless(static fn () => $deal->describe(
                $input->title instanceof Omitted ? $deal->title() : $input->title,
                $input->description instanceof Omitted ? $deal->description() : $input->description,
                $now,
            ));
            if (!$input->ownerUserId instanceof Omitted) {
                $deal->assignTo($this->owner($input->ownerUserId), $now);
            }
            $persons = $input->contactPersonIds instanceof Omitted ? null : $input->contactPersonIds;
            if (!$input->clientPartyId instanceof Omitted && $input->clientPartyId !== $deal->clientPartyId()) {
                $client = $this->newDealClient($input->clientPartyId);
                if ([] !== $this->store->linksOf($deal->id())) {
                    throw SalesRefused::conflict('client_has_links', 'The deal is linked to other deals, and a link joins deals of one client only; remove its links with foresi_remove_deal_link first.', 'clientPartyId');
                }
                $this->denyIfProjectOfClient($deal, $client->id);
                $deal->changeClient($client->id, $now);
                $persons ??= [];
            }
            $persons = null === $persons ? null : $this->persons($deal->clientPartyId(), $persons);
            if ($newPerson instanceof ContactPersonDraft) {
                $persons = $this->withNewPerson($deal->clientPartyId(), $persons ?? array_map(static fn (DealContact $contact): string => $contact->personId(), $this->store->contactsOf($deal->id())), $newPerson);
            }
            if (null !== $persons) {
                $this->replaceContacts($deal, $persons);
            }
            $this->updated($deal, null === $customFields ? [] : $this->customFields->write(DealExtension::RECORD, $deal->id(), $customFields, false));

            return $deal;
        });
    }

    /**
     * Moves an open deal to another stage; to the stage it is at changes nothing.
     *
     * @throws SalesRefused
     */
    public function changeStage(ChangeDealStageInput $input): Deal
    {
        $this->access->denyUnlessStaff();

        return $this->work->run(function () use ($input): Deal {
            $deal = $this->deal($input->dealId);
            $from = $deal->stageId();
            $stage = $this->openStage($input->stageId);
            if (SalesRefused::unless(fn (): bool => $deal->moveTo($stage->id(), $this->clock->now()))) {
                $this->events->record(new DealStageChanged($deal->id(), $deal->status()->value, $from, $deal->stageId()));
            }

            return $deal;
        });
    }

    /**
     * @throws SalesRefused
     */
    public function win(WinDealInput $input): Deal
    {
        $this->access->denyUnlessStaff();

        return $this->work->run(function () use ($input): Deal {
            $deal = $this->deal($input->dealId);
            SalesRefused::unless(fn () => $deal->win($this->clock->now()));
            $this->events->record(new DealWon($deal->id(), $deal->status()->value, $deal->stageId()));
            // A deal won for the first time gets its starting plan, each entry without a day (D1).
            $this->billingPlan->startPlan($deal);

            return $deal;
        });
    }

    /**
     * @throws SalesRefused
     */
    public function lose(LoseDealInput $input): Deal
    {
        $this->access->denyUnlessStaff();

        return $this->work->run(function () use ($input): Deal {
            $deal = $this->deal($input->dealId);
            $reason = null === $input->lostReasonId ? null : $this->openReason($input->lostReasonId);
            SalesRefused::unless(fn () => $deal->lose($reason?->id(), $input->note, $this->clock->now()));
            $this->events->record(new DealLost($deal->id(), $deal->status()->value, $deal->stageId(), $deal->lostReasonId()));

            return $deal;
        });
    }

    /**
     * @throws SalesRefused
     */
    public function reopen(ReopenDealInput $input): Deal
    {
        $this->access->denyUnlessStaff();

        return $this->work->run(function () use ($input): Deal {
            $deal = $this->deal($input->dealId);
            SalesRefused::unless(fn () => $deal->reopen($this->clock->now()));
            $this->events->record(new DealReopened($deal->id(), $deal->status()->value, $deal->stageId()));

            return $deal;
        });
    }

    /**
     * Puts a deal in the trash; trashing it again changes nothing and records nothing.
     *
     * @throws SalesRefused
     */
    public function trash(TrashDealInput $input): Deal
    {
        $userId = $this->access->denyUnlessStaff();

        return $this->work->run(function () use ($input, $userId): Deal {
            $deal = $this->store->deal($input->dealId) ?? throw $this->noDeal();
            if ($deal->trash($userId, $this->clock->now())) {
                $this->events->record(new DealTrashed($deal->id(), $deal->status()->value, $deal->stageId()));
            }

            return $deal;
        });
    }

    /**
     * Brings a deal back from the trash; one that is not there changes nothing and records nothing.
     *
     * @throws SalesRefused
     */
    public function restore(RestoreDealInput $input): Deal
    {
        $this->access->denyUnlessStaff();

        return $this->work->run(function () use ($input): Deal {
            $deal = $this->store->deal($input->dealId) ?? throw $this->noDeal();
            if ($deal->restore($this->clock->now())) {
                $this->events->record(new DealRestored($deal->id(), $deal->status()->value, $deal->stageId()));
            }

            return $deal;
        });
    }

    /**
     * A new deal for the same client that follows from this one: the contact
     * persons still linked to the client come along, and the lines named are
     * copied as they are.
     *
     * @throws SalesRefused
     */
    public function createFollowUp(CreateFollowUpDealInput $input): Deal
    {
        $caller = $this->access->denyUnlessStaff();

        return $this->work->run(function () use ($input, $caller): Deal {
            $source = $this->deal($input->dealId);
            $client = $this->newDealClient($source->clientPartyId());
            $lines = $this->linesToCopy($source, $input->lineIds);
            $deal = SalesRefused::unless(fn (): Deal => new Deal(
                $input->title,
                '',
                $client->id,
                $this->owner($input->ownerUserId ?? $caller),
                $this->startStage($input->stageId)->id(),
                $this->clock->now(),
            ));
            $this->store->add($deal);
            $linked = array_map(static fn (PartyPersonSummary $person): string => $person->id, $this->parties->personsOf($client->id));
            $contacts = array_map(static fn (DealContact $contact): string => $contact->personId(), $this->store->contactsOf($source->id()));
            $this->replaceContacts($deal, array_values(array_intersect($contacts, $linked)));
            foreach ($lines as $position => $line) {
                $this->store->add($line->copyFor($deal->id(), $position));
            }
            $this->store->add(new DealLink($deal->id(), $source->id(), DealLinkType::FollowsFrom));
            $source->touch($this->clock->now());
            $this->events->record(new DealCreated($deal->id(), $deal->status()->value, $deal->stageId()));
            $this->updated($source);

            return $deal;
        });
    }

    /**
     * @throws SalesRefused not_found
     */
    private function deal(string $id): Deal
    {
        $deal = $this->store->deal($id);
        if (!$deal instanceof Deal || $deal->isTrashed()) {
            throw $this->noDeal();
        }

        return $deal;
    }

    private function noDeal(): SalesRefused
    {
        return SalesRefused::notFound('There is no deal with this id. Find it with foresi_search_deals.');
    }

    /**
     * @param list<string> $customFields the keys of the custom fields that changed
     */
    private function updated(Deal $deal, array $customFields = []): void
    {
        $this->events->record(new DealUpdated($deal->id(), $deal->status()->value, $deal->stageId(), $customFields));
    }

    /**
     * Refuses another client while a line links a project of a client other than the new
     * one, or a project the person does not see; a project of no client goes with any.
     *
     * @throws SalesRefused conflict
     */
    private function denyIfProjectOfClient(Deal $deal, string $clientId): void
    {
        foreach ($this->store->linesOf($deal->id()) as $line) {
            $projectId = $line->projectId();
            if (null === $projectId) {
                continue;
            }
            $project = $this->projects->find($projectId);
            if (!$project instanceof LineProject || (null !== $project->clientId && $project->clientId !== $clientId)) {
                throw SalesRefused::conflict('client_has_projects', 'A line of the deal links a project of its client, and a line links only a project of the deal\'s client; unlink the project on the line first.', 'clientPartyId', ['lineId' => $line->id(), 'projectId' => $projectId]);
            }
        }
    }

    /**
     * The client of a new deal: a contact of the address book that is not archived.
     *
     * @throws SalesRefused
     */
    private function newDealClient(string $id): PartySummary
    {
        $client = $this->parties->find($id) ?? throw SalesRefused::invalid('client_unknown', 'clientPartyId', 'The address book has no contact with this id. Find the client with foresi_search_contacts.');
        if ($client->archived) {
            throw SalesRefused::conflict('client_archived', 'The client is archived and takes no new deal; restore the contact first.', 'clientPartyId');
        }

        return $client;
    }

    /**
     * The contact persons as given, once each and in order, each linked to the client.
     *
     * @param list<mixed> $ids
     *
     * @return list<string>
     *
     * @throws SalesRefused invalid
     */
    private function persons(string $clientId, array $ids): array
    {
        $linked = array_map(static fn (PartyPersonSummary $person): string => $person->id, $this->parties->personsOf($clientId));
        $persons = [];
        foreach ($ids as $id) {
            if (!\is_string($id) || !\in_array($id, $linked, true)) {
                throw SalesRefused::invalid('contact_person_not_of_client', 'contactPersonIds', 'Each contact person is linked to the client in the address book; link them there first.');
            }
            if (!\in_array($id, $persons, true)) {
                $persons[] = $id;
            }
        }

        return $persons;
    }

    /**
     * The contact persons with the person of the dialog added to the client and put last.
     *
     * @param list<string> $persons
     *
     * @return list<string>
     *
     * @throws ContactPersonRefused
     */
    private function withNewPerson(string $clientId, array $persons, ContactPersonDraft $draft): array
    {
        $person = $draft->isExisting()
            ? $this->contactPersons->link($clientId, (string) $draft->personId, $draft->position)
            : $this->contactPersons->addNew($clientId, $draft->name, $draft->email, $draft->position);
        if (!\in_array($person->id, $persons, true)) {
            $persons[] = $person->id;
        }

        return $persons;
    }

    /**
     * Makes the deal's contact persons these, in this order.
     *
     * @param list<string> $personIds
     */
    private function replaceContacts(Deal $deal, array $personIds): void
    {
        $kept = [];
        foreach ($this->store->contactsOf($deal->id()) as $contact) {
            $position = array_search($contact->personId(), $personIds, true);
            if (false === $position) {
                $this->store->remove($contact);
            } else {
                $contact->moveTo($position);
                $kept[] = $contact->personId();
            }
        }
        foreach ($personIds as $position => $personId) {
            if (!\in_array($personId, $kept, true)) {
                $this->store->add(new DealContact($deal->id(), $personId, $position));
            }
        }
    }

    /**
     * @throws SalesRefused invalid
     */
    private function owner(string $userId): string
    {
        if (!$this->access->isStaffMember($userId)) {
            throw SalesRefused::invalid('owner_not_staff', 'ownerUserId', 'The owner of a deal is an owner or member of the organisation.');
        }

        return $userId;
    }

    /**
     * The stage a new deal starts at: the one named, else the first.
     *
     * @throws SalesRefused
     */
    private function startStage(?string $id): Stage
    {
        if (null !== $id) {
            return $this->openStage($id);
        }

        return $this->store->stages()[0] ?? throw SalesRefused::conflict('no_stage', 'Every stage is archived; create one under Settings → Sales first.');
    }

    /**
     * @throws SalesRefused invalid
     */
    private function openStage(string $id): Stage
    {
        $stage = $this->store->stage($id) ?? throw SalesRefused::invalid('stage_unknown', 'stageId', 'There is no stage with this id. List them with foresi_list_deal_stages.');
        if ($stage->isArchived()) {
            throw SalesRefused::invalid('stage_archived', 'stageId', 'The stage is archived; pick another with foresi_list_deal_stages.');
        }

        return $stage;
    }

    /**
     * @throws SalesRefused invalid
     */
    private function openReason(string $id): LostReason
    {
        $reason = $this->store->lostReason($id) ?? throw SalesRefused::invalid('lost_reason_unknown', 'lostReasonId', 'There is no lost reason with this id. List them with foresi_list_deal_stages.');
        if ($reason->isArchived()) {
            throw SalesRefused::invalid('lost_reason_archived', 'lostReasonId', 'The lost reason is archived; pick another with foresi_list_deal_stages.');
        }

        return $reason;
    }

    /**
     * The lines of the deal named, in their order on it.
     *
     * @param list<mixed> $ids
     *
     * @return list<DealLine>
     *
     * @throws SalesRefused invalid
     */
    private function linesToCopy(Deal $deal, array $ids): array
    {
        $lines = $this->store->linesOf($deal->id());
        $known = array_map(static fn (DealLine $line): string => $line->id(), $lines);
        foreach ($ids as $id) {
            if (!\is_string($id) || !\in_array($id, $known, true)) {
                throw SalesRefused::invalid('line_unknown', 'lineIds', 'Each line to copy is a line of the deal the new one follows from.');
            }
        }

        return array_values(array_filter($lines, static fn (DealLine $line): bool => \in_array($line->id(), $ids, true)));
    }
}
