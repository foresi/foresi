<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Contacts\Application;

use Foresi\Contacts\Application\ContactPartRefused;
use Foresi\Contacts\Application\ManageContactPersons;
use Foresi\Contacts\Application\ManagePartyAddresses;
use Foresi\Contacts\Application\ManagePartyBankAccounts;
use Foresi\Events\Infrastructure\Dbal\EventLogTable;
use Foresi\Party\Application\PartyDetails;
use Foresi\Party\Application\PartyNotAllowed;
use Foresi\Party\Application\PartyRefused;
use Foresi\Party\Application\UnknownParty;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyBankAccount;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Tests\Party\Application\Query\ContactTestCase;

/**
 * The contact persons, bank accounts and addresses of a contact as its tabs
 * change them: what is entered is trimmed, each limit takes exactly its number
 * of characters, every wrong field is named at once, a guest is refused before
 * anything else, and each change tells the listeners that the contact changed.
 * The tests that need the Czech rules are in Integration/Country.
 */
final class ContactPartsTest extends ContactTestCase
{
    private const string IBAN = 'CZ6508000000192000145399';
    private const string OTHER_IBAN = 'CZ5508000000001234567899';

    // Contact persons

    public function testANewPersonIsTrimmedAndTakesEachLimitInCharacters(): void
    {
        $company = $this->contact('Alpha Example');
        $email = str_repeat('a', 242).'@example.com';

        $link = $this->persons()->addNew($company, ' '.str_repeat('č', 255).' ', ' '.$email.' ', ' '.str_repeat('á', 64).' ', ' '.str_repeat('é', 100).' ', true);

        $person = $link->person();
        self::assertSame(str_repeat('č', 255), $person->displayName());
        self::assertSame($email, $this->details()->generalEmail($person)?->address());
        self::assertSame(str_repeat('á', 64), $person->phone());
        self::assertSame(str_repeat('é', 100), $link->position());
        // The person has the country of the company's billing address.
        self::assertSame('CZ', $this->details()->billingAddress($person)?->address()->countryCode);
    }

    public function testANewPersonOfACompanyWithNoBillingAddressHasNoCountry(): void
    {
        $company = new Party(PartyKind::Company, 'Alpha Example', new RegistrationNumber('CZ', '12345679'), null, '');
        $this->em->persist($company);
        $this->em->flush();

        $link = $this->persons()->addNew($company, 'Petra Example', '', '', '', false);

        self::assertSame('', $this->details()->billingAddress($link->person())?->address()->countryCode ?? '');
    }

    public function testANewPersonRefusesEveryValueOneCharacterTooLong(): void
    {
        $company = $this->contact('Alpha Example');

        $refused = $this->refused(fn (): \Foresi\Party\Domain\ContactLink => $this->persons()->addNew($company, str_repeat('č', 256), str_repeat('a', 243).'@example.com', str_repeat('á', 65), str_repeat('é', 101), false));

        self::assertSame(['name' => 'too_long', 'email' => 'email', 'phone' => 'too_long', 'position' => 'too_long'], $refused);
        self::assertSame(['name' => 'required'], $this->refused(fn (): \Foresi\Party\Domain\ContactLink => $this->persons()->addNew($company, '  ', '', '', '', false)));
    }

    public function testAGuestAddsNoPersonAndMovesNoPrimaryLink(): void
    {
        $company = $this->contact('Alpha Example');

        $this->signIn($this->guest);
        $this->assertNotAllowed(fn (): \Foresi\Party\Domain\ContactLink => $this->persons()->addNew($company, '', '', '', '', false));
        $this->assertNotAllowed(fn () => $this->persons()->makePrimary($company, 'unknown'));
    }

    public function testThePrimaryLinkMovesToTheChosenOne(): void
    {
        $company = $this->contact('Alpha Example');
        $first = $this->persons()->addNew($company, 'Petra Example', '', '', '', true);
        $second = $this->persons()->addNew($company, 'Pavel Example', '', '', '', false);

        $this->persons()->makePrimary($company, $second->id());

        self::assertFalse($first->isPrimary());
        self::assertTrue($second->isPrimary());
    }

    public function testOnlyALinkOfTheCompanyIsMadePrimary(): void
    {
        $company = $this->contact('Alpha Example');
        $link = $this->persons()->addNew($company, 'Petra Example', '', '', '', false);

        $this->assertPartyRefused(fn () => $this->persons()->makePrimary($company, 'unknown'));
        // The person's side of the link is not the person's own link to move.
        $this->assertPartyRefused(fn () => $this->persons()->makePrimary($link->person(), $link->id()));
        self::assertFalse($link->isPrimary());
    }

    public function testAnAccountRefusesEveryWrongFieldAtOnce(): void
    {
        $party = $this->contact('Alpha Example');
        $this->accounts()->add($party, self::IBAN, '', '', false);

        self::assertSame(['bic' => 'bic', 'label' => 'too_long', 'number' => 'required'], $this->refused(fn (): PartyBankAccount => $this->accounts()->add($party, '   ', 'KOMB', str_repeat('ů', 101), false)));
        self::assertSame(['number' => 'account_number'], $this->refused(fn (): PartyBankAccount => $this->accounts()->add($party, 'not a number', '', '', false)));
        self::assertSame(['number' => 'duplicate_account'], $this->refused(fn (): PartyBankAccount => $this->accounts()->add($party, ' '.self::IBAN.' ', '', '', false)));
        // A BIC is the whole value: nothing before it, nothing after it.
        self::assertSame(['bic' => 'bic'], $this->refused(fn (): PartyBankAccount => $this->accounts()->add($party, self::OTHER_IBAN, '1KOMBCZPP', '', false)));
        self::assertSame(['bic' => 'bic'], $this->refused(fn (): PartyBankAccount => $this->accounts()->add($party, self::OTHER_IBAN, 'KOMBCZPP1', '', false)));
        self::assertSame(['label' => 'too_long'], $this->refused(fn (): PartyBankAccount => $this->accounts()->change($party, $this->onlyAccount($party)->id(), '', str_repeat('ů', 101))));
        self::assertCount(1, $this->details()->bankAccounts($party));
    }

    public function testAnAccountChangesItsBicAndLabel(): void
    {
        $party = $this->contact('Alpha Example');
        $account = $this->accounts()->add($party, self::IBAN, '', '', false);

        $this->accounts()->change($party, $account->id(), ' komb czpp ', ' Main ');

        self::assertSame('KOMBCZPP', $account->bic());
        self::assertSame('Main', $account->label());
        self::assertSame([$party->id(), $party->id()], $this->events());
    }

    public function testAnotherAccountIsMadePrimaryAndTheDeletedPrimaryHandsItOn(): void
    {
        $party = $this->contact('Alpha Example');
        $first = $this->accounts()->add($party, self::IBAN, '', '', false);
        $second = $this->accounts()->add($party, self::OTHER_IBAN, '', '', false);

        $this->accounts()->makePrimary($party, $first->id());
        self::assertCount(2, $this->events());
        $this->accounts()->makePrimary($party, $second->id());
        self::assertCount(3, $this->events());
        self::assertTrue($second->isPrimary());
        self::assertFalse($first->isPrimary());

        $this->accounts()->remove($party, $second->id());
        self::assertTrue($first->isPrimary());
        self::assertSame([$first], $this->details()->bankAccounts($party));
        self::assertCount(4, $this->events());

        // The last account goes too, with no account to take its place.
        $this->accounts()->remove($party, $first->id());
        self::assertSame([], $this->details()->bankAccounts($party));
    }

    public function testAGuestChangesNoAccount(): void
    {
        $party = $this->contact('Alpha Example');

        $this->signIn($this->guest);
        $this->assertNotAllowed(fn (): PartyBankAccount => $this->accounts()->add($party, '', '', '', false));
        $this->assertNotAllowed(fn (): PartyBankAccount => $this->accounts()->change($party, 'unknown', '', ''));
        $this->assertNotAllowed(fn () => $this->accounts()->makePrimary($party, 'unknown'));
        $this->assertNotAllowed(fn () => $this->accounts()->remove($party, 'unknown'));
    }

    // Addresses

    public function testAnAddressIsTrimmedAndTakesEachLimitInCharacters(): void
    {
        $party = $this->contact('Alpha Example');

        $address = $this->addresses()->add($party, 'delivery', ' '.str_repeat('š', 255).' ', ' '.str_repeat('ř', 128).' ', ' '.str_repeat('ž', 32).' ', ' sk ', ' '.str_repeat('ů', 100).' ');

        self::assertSame(AddressType::Delivery, $address->type());
        self::assertEquals(new Address(str_repeat('š', 255), str_repeat('ř', 128), str_repeat('ž', 32), 'SK'), $address->address());
        self::assertSame(str_repeat('ů', 100), $address->label());
        self::assertSame([$party->id()], $this->events());
    }

    public function testAnAddressRefusesEveryWrongFieldAtOnce(): void
    {
        $party = $this->contact('Alpha Example');

        self::assertSame(
            ['street' => 'too_long', 'city' => 'too_long', 'postalCode' => 'too_long', 'countryCode' => 'country', 'label' => 'too_long', 'type' => 'address_type'],
            $this->refused(fn (): PartyAddress => $this->addresses()->add($party, 'nonsense', str_repeat('š', 256), str_repeat('ř', 129), str_repeat('ž', 33), 'xx', str_repeat('ů', 101))),
        );
        self::assertSame(
            ['street' => 'required', 'city' => 'required', 'postalCode' => 'required', 'countryCode' => 'required'],
            $this->refused(fn (): PartyAddress => $this->addresses()->add($party, 'postal', '  ', '  ', '  ', '  ', '')),
        );
        self::assertSame(['type' => 'address_type'], $this->refused(fn (): PartyAddress => $this->addresses()->add($party, 'nonsense', 'Example Street 2', 'Example City', '100 00', 'CZ', '')));
        // No second billing address.
        self::assertSame(['type' => 'address_type'], $this->refused(fn (): PartyAddress => $this->addresses()->add($party, 'billing', 'Example Street 2', 'Example City', '100 00', 'CZ', '')));
        self::assertCount(1, $this->details()->addresses($party));
        self::assertSame([], $this->events());
    }

    public function testAnAddressChangesInPlaceOrAsAnotherType(): void
    {
        $party = $this->contact('Alpha Example');
        $address = $this->addresses()->add($party, 'delivery', 'Example Street 2', 'Example City', '100 00', 'CZ', 'Warehouse');

        $same = $this->addresses()->change($party, $address->id(), 'delivery', ' Example Street 3 ', ' Other City ', ' 200 00 ', ' sk ', ' Store ');
        self::assertSame($address, $same);
        self::assertEquals(new Address('Example Street 3', 'Other City', '200 00', 'SK'), $same->address());
        self::assertSame('Store', $same->label());

        $postal = $this->addresses()->change($party, $address->id(), 'postal', 'Example Street 3', 'Other City', '200 00', 'SK', 'Store');
        self::assertSame(AddressType::Postal, $postal->type());
        self::assertNotSame($address->id(), $postal->id());
        self::assertCount(2, $this->details()->addresses($party));
        self::assertCount(3, $this->events());
    }

    public function testTheBillingAddressChangesButStaysTheBillingOne(): void
    {
        $party = $this->contact('Alpha Example');
        $billing = $this->details()->billingAddress($party) ?? self::fail();
        $delivery = $this->addresses()->add($party, 'delivery', 'Example Street 2', 'Example City', '100 00', 'CZ', '');

        $changed = $this->addresses()->change($party, $billing->id(), 'delivery', 'Example Street 9', 'Example City', '100 00', 'CZ', '');
        self::assertSame($billing, $changed);
        self::assertSame(AddressType::Billing, $changed->type());
        self::assertSame('Example Street 9', $changed->address()->street);

        self::assertSame(['type' => 'address_type'], $this->refused(fn (): PartyAddress => $this->addresses()->change($party, $delivery->id(), 'billing', 'Example Street 2', 'Example City', '100 00', 'CZ', '')));
        self::assertSame(['type' => 'address_type'], $this->refused(fn (): PartyAddress => $this->addresses()->change($party, $delivery->id(), 'nonsense', 'Example Street 2', 'Example City', '100 00', 'CZ', '')));
        self::assertSame(['type' => 'address_type'], $this->refused(fn () => $this->addresses()->remove($party, $billing->id())));
        self::assertCount(2, $this->details()->addresses($party));
    }

    public function testAnAddressIsRemoved(): void
    {
        $party = $this->contact('Alpha Example');
        $address = $this->addresses()->add($party, 'other', 'Example Street 2', 'Example City', '100 00', 'CZ', '');

        $this->addresses()->remove($party, $address->id());

        self::assertCount(1, $this->details()->addresses($party));
        self::assertCount(2, $this->events());
        $this->expectException(UnknownParty::class);
        $this->addresses()->stored($party, $address->id());
    }

    public function testAGuestChangesNoAddress(): void
    {
        $party = $this->contact('Alpha Example');
        $billing = $this->details()->billingAddress($party) ?? self::fail();

        $this->signIn($this->guest);
        $this->assertNotAllowed(fn (): PartyAddress => $this->addresses()->add($party, 'nonsense', '', '', '', '', ''));
        $this->assertNotAllowed(fn (): PartyAddress => $this->addresses()->change($party, 'unknown', 'delivery', '', '', '', '', ''));
        $this->assertNotAllowed(fn () => $this->addresses()->remove($party, $billing->id()));
    }

    private function persons(): ManageContactPersons
    {
        return self::getContainer()->get(ManageContactPersons::class);
    }

    private function accounts(): ManagePartyBankAccounts
    {
        return self::getContainer()->get(ManagePartyBankAccounts::class);
    }

    private function addresses(): ManagePartyAddresses
    {
        return self::getContainer()->get(ManagePartyAddresses::class);
    }

    private function details(): PartyDetails
    {
        return self::getContainer()->get(PartyDetails::class);
    }

    private function onlyAccount(Party $party): PartyBankAccount
    {
        $accounts = $this->details()->bankAccounts($party);
        self::assertCount(1, $accounts);

        return $accounts[0];
    }

    /**
     * @param \Closure(): mixed $write
     *
     * @return array<string, string>
     */
    private function refused(\Closure $write): array
    {
        try {
            $write();
        } catch (ContactPartRefused $refused) {
            return $refused->reasons;
        }
        self::fail('The contact part was not refused.');
    }

    /**
     * @param \Closure(): mixed $write
     */
    private function assertPartyRefused(\Closure $write): void
    {
        try {
            $write();
            self::fail('The link was not refused.');
        } catch (PartyRefused $refused) {
            self::assertSame(PartyRefused::NOT_LINKED, $refused->reason);
        }
    }

    /**
     * @param \Closure(): mixed $write
     */
    private function assertNotAllowed(\Closure $write): void
    {
        try {
            $write();
            self::fail('A guest changed a contact.');
        } catch (PartyNotAllowed) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @return list<string> the contacts that contacts.contact.updated was recorded for, in order
     *
     * @phpstan-impure it reads the event log, which every change writes to
     */
    private function events(): array
    {
        $subjects = $this->em->getConnection()->fetchFirstColumn(
            'SELECT '.EventLogTable::SUBJECT_ID.' FROM '.EventLogTable::LOG.' WHERE '.EventLogTable::NAME.' = ? ORDER BY '.EventLogTable::ID,
            ['contacts.contact.updated'],
        );

        return array_map(static fn (mixed $subject): string => \is_string($subject) ? $subject : '', $subjects);
    }
}
