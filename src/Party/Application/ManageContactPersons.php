<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\ContactLink;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyEmail;
use Foresi\Party\Domain\PartyKind;

/**
 * The contact persons of a company or sole trader, as its Contact persons tab
 * changes them: a new person written and linked in one go, and the primary
 * link moved. Linking an existing person and unlinking are LinkContactPerson
 * and UnlinkContactPerson.
 */
final readonly class ManageContactPersons
{
    public function __construct(
        private CreateParty $create,
        private LinkContactPerson $link,
        private PartyDetails $details,
        private TransactionalWork $transaction,
        private PartyAccess $access,
    ) {
    }

    /**
     * A new contact of the kind Person, with no address of its own (the country
     * of the company's billing address), linked to the company. Both are
     * stored, or neither.
     *
     * @throws PartyNotAllowed    for a guest
     * @throws ContactPartRefused with every field that is wrong
     */
    public function addNew(Party $company, string $name, string $email, string $phone, string $position, bool $primary): ContactLink
    {
        $this->access->denyUnlessMayChange();
        $name = trim($name);
        $email = trim($email);
        $phone = trim($phone);
        $position = trim($position);
        $reasons = [];
        if ('' === $name) {
            $reasons['name'] = ContactPartRefused::REQUIRED;
        } elseif (mb_strlen($name) > PartyRules::MAX_NAME) {
            $reasons['name'] = ContactPartRefused::TOO_LONG;
        }
        if ('' !== $email && (\strlen($email) > PartyRules::MAX_EMAIL || !PartyEmail::isValid($email))) {
            $reasons['email'] = ContactPartRefused::EMAIL;
        }
        if (mb_strlen($phone) > PartyRules::MAX_PHONE) {
            $reasons['phone'] = ContactPartRefused::TOO_LONG;
        }
        if (mb_strlen($position) > PartyRules::MAX_POSITION) {
            $reasons['position'] = ContactPartRefused::TOO_LONG;
        }
        if (PartyKind::Person === $company->kind()) {
            throw new PartyRefused(PartyRefused::PERSON, 'personId');
        }
        if ([] !== $reasons) {
            throw new ContactPartRefused($reasons);
        }
        $country = $this->details->billingAddress($company)?->address()->countryCode ?? '';

        return $this->transaction->run(function () use ($company, $name, $email, $phone, $position, $primary, $country): ContactLink {
            $person = $this->create->createFrom(new PartyInput(PartyKind::Person, $name, null, null, $email, $phone, [], new Address('', '', '', $country)));

            return $this->link->link($company, $person, $position, $primary);
        });
    }

    /**
     * Makes the link the company's primary one and demotes the previous one.
     *
     * @throws PartyNotAllowed for a guest
     * @throws PartyRefused    when the link is not one of the company's
     */
    public function makePrimary(Party $company, string $linkId): void
    {
        $this->access->denyUnlessMayChange();
        $links = array_values(array_filter($this->details->contactLinks($company), static fn (ContactLink $link): bool => $link->company() === $company));
        $chosen = array_find($links, static fn (ContactLink $link): bool => $link->id() === $linkId) ?? throw new PartyRefused(PartyRefused::NOT_LINKED, 'personId');
        $this->transaction->run(static function () use ($links, $chosen): void {
            foreach ($links as $link) {
                $link->markPrimary($link === $chosen);
            }
        });
    }
}
