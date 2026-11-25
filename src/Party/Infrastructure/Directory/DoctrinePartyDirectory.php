<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Directory;

use Foresi\Party\Application\Query\ContactDetail;
use Foresi\Party\Application\Query\ContactDetails;
use Foresi\Party\Application\Query\ContactPersonLink;
use Foresi\Party\Application\UnknownParty as NoSuchContact;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Party\Contract\PartyPersonSummary;
use Foresi\Party\Contract\PartySummary;
use Foresi\Party\Contract\UnknownParty;

/**
 * The contract over the address book's own reads of one contact (ContactDetails), so the
 * access rule and the queries stay in one place.
 */
final readonly class DoctrinePartyDirectory implements PartyDirectory
{
    public function __construct(private ContactDetails $contacts, private PartySummaryFactory $summaries)
    {
    }

    public function find(string $id): ?PartySummary
    {
        $detail = $this->detail($id);

        return $detail instanceof ContactDetail ? $this->summary($detail) : null;
    }

    public function get(string $id): PartySummary
    {
        return $this->find($id) ?? throw new UnknownParty($id);
    }

    public function personsOf(string $companyId): array
    {
        $company = $this->detail($companyId);
        if (!$company instanceof ContactDetail) {
            return [];
        }
        $persons = [];
        foreach ($company->contactLinks as $link) {
            if ($link->companyId !== $companyId) {
                continue;
            }
            $person = $this->detail($link->personId);
            if ($person instanceof ContactDetail) {
                $persons[] = $this->person($person, $link);
            }
        }

        return $persons;
    }

    private function detail(string $id): ?ContactDetail
    {
        try {
            return $this->contacts->of($id);
        } catch (NoSuchContact) {
            return null;
        }
    }

    private function summary(ContactDetail $detail): PartySummary
    {
        $contact = $detail->contact;

        // The billing address comes first; every contact has one, the first address is the fallback.
        return $this->summaries->of($contact, $contact->billingAddress ?? ($detail->addresses[0] ?? null)?->address, $detail->phone, $detail->mobile);
    }

    private function person(ContactDetail $person, ContactPersonLink $link): PartyPersonSummary
    {
        return new PartyPersonSummary($person->contact->id, $person->contact->name, $person->contact->email, $this->phone($person), '' === $link->position ? null : $link->position);
    }

    private function phone(ContactDetail $detail): ?string
    {
        return '' !== $detail->phone ? $detail->phone : ('' !== $detail->mobile ? $detail->mobile : null);
    }
}
