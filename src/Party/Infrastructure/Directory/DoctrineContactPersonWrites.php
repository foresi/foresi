<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Directory;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Party\Application\ContactPartRefused;
use Foresi\Party\Application\LinkContactPerson;
use Foresi\Party\Application\ManageContactPersons;
use Foresi\Party\Application\PartyDetails;
use Foresi\Party\Application\PartyQuery;
use Foresi\Party\Application\PartyRefused;
use Foresi\Party\Application\Query\ContactDetails;
use Foresi\Party\Application\UnknownParty as NoSuchContact;
use Foresi\Party\Contract\ContactPersonRefused;
use Foresi\Party\Contract\ContactPersonWrites;
use Foresi\Party\Contract\PartyPersonSummary;
use Foresi\Party\Contract\UnknownParty;
use Foresi\Party\Domain\ContactLink;
use Foresi\Party\Domain\EmailPurpose;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyEmail;
use Foresi\Party\Domain\PartyKind;

/**
 * The contract over the address book's own writes of a contact person (LinkContactPerson,
 * ManageContactPersons), so their rules stay in one place: the same ones the contact's
 * Contact persons dialog follows. The access rule is ContactDetails', as the directory's.
 */
final readonly class DoctrineContactPersonWrites implements ContactPersonWrites
{
    private const array MESSAGES = [
        ContactPartRefused::REQUIRED => ContactPersonRefused::REQUIRED,
        ContactPartRefused::TOO_LONG => ContactPersonRefused::TOO_LONG,
        ContactPartRefused::EMAIL => ContactPersonRefused::EMAIL,
    ];

    public function __construct(
        private ContactDetails $contacts,
        private PartyQuery $parties,
        private PartyDetails $details,
        private LinkContactPerson $linkPerson,
        private ManageContactPersons $persons,
        private EntityManagerInterface $em,
    ) {
    }

    public function candidatesOf(string $companyId): array
    {
        $company = $this->find($companyId);
        if (!$company instanceof Party || PartyKind::Person === $company->kind()) {
            return [];
        }
        $candidates = $this->parties->contactPersonCandidates($company);
        $emails = $this->generalEmails($candidates);

        return array_map(fn (Party $person): PartyPersonSummary => new PartyPersonSummary(
            $person->id(),
            $person->displayName(),
            $emails[$person->id()] ?? null,
            $this->phone($person),
            null,
        ), $candidates);
    }

    public function link(string $companyId, string $personId, string $position): PartyPersonSummary
    {
        $company = $this->company($companyId);
        $person = array_find($this->parties->contactPersonCandidates($company), static fn (Party $candidate): bool => $candidate->id() === $personId);
        if (PartyKind::Person === $company->kind() || !$person instanceof Party) {
            throw new ContactPersonRefused(['person' => ContactPersonRefused::PERSON]);
        }
        try {
            $link = $this->linkPerson->link($company, $person, trim($position), !$this->hasPrimary($company));
        } catch (PartyRefused $refused) {
            throw $this->refused($refused);
        }

        return $this->summary($link, $this->generalEmails([$person])[$person->id()] ?? null);
    }

    public function addNew(string $companyId, string $name, string $email, string $position): PartyPersonSummary
    {
        $company = $this->company($companyId);
        try {
            $link = $this->persons->addNew($company, $name, $email, '', $position, !$this->hasPrimary($company));
        } catch (ContactPartRefused $refused) {
            throw new ContactPersonRefused(array_map(static fn (string $reason): string => self::MESSAGES[$reason] ?? ContactPersonRefused::TOO_LONG, $refused->reasons));
        } catch (PartyRefused $refused) {
            throw $this->refused($refused);
        }

        $email = trim($email);

        return $this->summary($link, '' === $email ? null : $email);
    }

    private function find(string $id): ?Party
    {
        try {
            return $this->contacts->party($id);
        } catch (NoSuchContact) {
            return null;
        }
    }

    /**
     * @throws UnknownParty
     */
    private function company(string $id): Party
    {
        return $this->find($id) ?? throw new UnknownParty($id);
    }

    private function hasPrimary(Party $company): bool
    {
        return array_any($this->details->contactLinks($company), static fn (ContactLink $link): bool => $link->company() === $company && $link->isPrimary());
    }

    private function refused(PartyRefused $refused): ContactPersonRefused
    {
        if ('position' === $refused->field) {
            return new ContactPersonRefused(['position' => ContactPersonRefused::TOO_LONG]);
        }

        return new ContactPersonRefused(['person' => ContactPersonRefused::PERSON]);
    }

    /**
     * The person as the directory shows it, from the link just made: it is stored when the
     * unit of work it joined ends, so the queries do not see it yet.
     */
    private function summary(ContactLink $link, ?string $email): PartyPersonSummary
    {
        $person = $link->person();

        return new PartyPersonSummary($person->id(), $person->displayName(), $email, $this->phone($person), '' === $link->position() ? null : $link->position());
    }

    /**
     * @param list<Party> $persons
     *
     * @return array<string, string> the general e-mail address by the person's id
     */
    private function generalEmails(array $persons): array
    {
        if ([] === $persons) {
            return [];
        }
        /** @var list<PartyEmail> $emails */
        $emails = $this->em->createQuery('SELECT e FROM '.PartyEmail::class.' e WHERE e.party IN (:persons) AND e.purpose = :purpose ORDER BY e.id')
            ->setParameter('persons', $persons)->setParameter('purpose', EmailPurpose::General)->getResult();
        $addresses = [];
        foreach ($emails as $email) {
            $addresses[$email->party()->id()] ??= $email->address();
        }

        return $addresses;
    }

    private function phone(Party $person): ?string
    {
        return '' !== $person->phone() ? $person->phone() : ('' !== $person->mobile() ? $person->mobile() : null);
    }
}
