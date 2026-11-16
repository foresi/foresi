<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application\Contact;

use App\Party\Application\ArchiveParty;
use App\Party\Application\CreateParty;
use App\Party\Application\DuplicateFinder;
use App\Party\Application\DuplicateMatch;
use App\Party\Application\LinkContactPerson;
use App\Party\Application\PartyAccess;
use App\Party\Application\PartyDetails;
use App\Party\Application\PartyDraft;
use App\Party\Application\PartyInput;
use App\Party\Application\PartyNotAllowed;
use App\Party\Application\PartyRefused;
use App\Party\Application\PartyRules;
use App\Party\Application\Query\ContactDetails;
use App\Party\Application\UnknownParty;
use App\Party\Application\UnlinkContactPerson;
use App\Party\Application\UpdateParty;
use App\Party\Domain\Party;
use App\Party\Domain\PartyRole;
use App\Party\Domain\RegistrationNumber;
use App\Shared\Application\Api\Omitted;

/**
 * The contact writes of the API and MCP (ADR 0032): each input goes through the
 * rules the screens use, a registration number or VAT id the organisation has
 * already is refused as a duplicate, and the use cases decide who may write.
 * Every method answers the id of the contact to read back.
 */
final readonly class ContactWrites
{
    public function __construct(
        private PartyAccess $access,
        private ContactDetails $contacts,
        private PartyDetails $details,
        private PartyRules $rules,
        private DuplicateFinder $duplicates,
        private CreateParty $create,
        private UpdateParty $update,
        private ArchiveParty $archive,
        private LinkContactPerson $link,
        private UnlinkContactPerson $unlink,
    ) {
    }

    /**
     * @throws PartyNotAllowed
     * @throws PartyRefused
     */
    public function create(CreateContactInput $input): string
    {
        // Asked first, so a guest learns nothing from a refusal of the rules or a duplicate.
        $this->access->denyUnlessMayChange();
        $parsed = $this->rules->parse(new PartyDraft(
            $input->kind,
            trim($input->displayName),
            strtoupper(trim($input->registrationCountry ?? $input->countryCode)),
            trim($input->registrationNumber),
            trim($input->vatId),
            trim($input->email),
            trim($input->phone),
            trim($input->mobile),
            trim($input->website),
            $input->roles,
            trim($input->street),
            trim($input->city),
            trim($input->postalCode),
            strtoupper(trim($input->countryCode)),
            trim($input->privateNote),
        ))->orRefuse();
        $this->refuseDuplicates($parsed, null);

        return $this->create->createFrom($parsed)->id();
    }

    /**
     * @throws PartyNotAllowed
     * @throws UnknownParty
     * @throws PartyRefused
     */
    public function update(UpdateContactInput $input): string
    {
        $party = $this->contacts->party($input->contactId);
        $address = $this->details->billingAddress($party)?->address();
        $number = $party->registrationNumber();
        $countryCode = $this->upper($this->given($input->countryCode, $address->countryCode ?? ''));
        $registrationNumber = $this->given($input->registrationNumber, $number?->toString() ?? '');
        // A number given without its country, or a country cleared, takes the country of the billing address.
        $registrationCountry = match (true) {
            null === $input->registrationCountry => $countryCode,
            !$input->registrationCountry instanceof Omitted => $this->upper($input->registrationCountry),
            $number instanceof RegistrationNumber && $number->toString() === $registrationNumber => $number->countryCode,
            default => $countryCode,
        };
        $parsed = $this->rules->parse(new PartyDraft(
            $party->kind()->value,
            $this->given($input->displayName, $party->displayName()),
            $registrationCountry,
            $registrationNumber,
            $this->given($input->vatId, $party->vatId()?->toString() ?? ''),
            $this->given($input->email, $this->details->generalEmail($party)?->address() ?? ''),
            $this->given($input->phone, $party->phone()),
            $this->given($input->mobile, $party->mobile()),
            $this->given($input->website, $party->website()),
            $input->roles instanceof Omitted ? array_map(static fn (PartyRole $role): string => $role->value, $party->roles()) : $input->roles,
            $this->given($input->street, $address->street ?? ''),
            $this->given($input->city, $address->city ?? ''),
            $this->given($input->postalCode, $address->postalCode ?? ''),
            $countryCode,
            $this->given($input->privateNote, $party->privateNote()),
        ))->orRefuse();
        if (!$this->sameIdentifiers($party, $parsed)) {
            $this->refuseDuplicates($parsed, $party->id());
        }
        $this->update->update($party, $parsed);

        return $party->id();
    }

    /**
     * @throws PartyNotAllowed
     * @throws UnknownParty
     */
    public function archive(ArchiveContactInput $input): string
    {
        $party = $this->contacts->party($input->contactId);
        $this->archive->archive([$party]);

        return $party->id();
    }

    /**
     * @throws PartyNotAllowed
     * @throws UnknownParty
     */
    public function restore(RestoreContactInput $input): string
    {
        $party = $this->contacts->party($input->contactId);
        $this->archive->restore([$party]);

        return $party->id();
    }

    /**
     * @throws PartyNotAllowed
     * @throws UnknownParty
     * @throws PartyRefused
     */
    public function linkPerson(LinkContactPersonInput $input): string
    {
        $company = $this->contacts->party($input->contactId);
        $this->link->link($company, $this->contacts->party($input->personId), trim($input->position), $input->primary);

        return $company->id();
    }

    /**
     * @throws PartyNotAllowed
     * @throws UnknownParty
     * @throws PartyRefused
     */
    public function unlinkPerson(UnlinkContactPersonInput $input): string
    {
        $company = $this->contacts->party($input->contactId);
        $this->unlink->unlink($company, $this->contacts->party($input->personId));

        return $company->id();
    }

    /**
     * @throws PartyRefused
     */
    private function refuseDuplicates(PartyInput $input, ?string $self): void
    {
        // The finder answers nothing for an input without identifiers, and one match per contact.
        $matches = $this->duplicates->find($input->registrationNumber, $input->vatId, true, $self);
        if ([] === $matches) {
            return;
        }
        $field = array_any($matches, static fn (DuplicateMatch $match): bool => $match->sameRegistrationNumber) ? 'registrationNumber' : 'vatId';

        throw new PartyRefused(PartyRefused::DUPLICATE, $field, array_map(static fn (DuplicateMatch $match): string => $match->partyId, $matches));
    }

    private function sameIdentifiers(Party $party, PartyInput $input): bool
    {
        $number = $party->registrationNumber();

        return $number?->countryCode === $input->registrationNumber?->countryCode
            && $number?->normalised() === $input->registrationNumber?->normalised()
            && $party->vatId()?->toString() === $input->vatId?->toString();
    }

    /**
     * The value sent, trimmed; the stored one when left out, nothing when cleared.
     */
    private function given(string|Omitted|null $sent, string $stored): string
    {
        return match (true) {
            null === $sent => '',
            $sent instanceof Omitted => $stored,
            default => trim($sent),
        };
    }

    private function upper(string $value): string
    {
        return strtoupper(trim($value));
    }
}
