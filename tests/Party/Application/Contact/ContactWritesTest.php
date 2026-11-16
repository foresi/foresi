<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Application\Contact;

use App\Events\Infrastructure\Dbal\EventLogTable;
use App\Party\Application\ArchiveParty;
use App\Party\Application\Contact\ArchiveContactInput;
use App\Party\Application\Contact\ContactWrites;
use App\Party\Application\Contact\CreateContactInput;
use App\Party\Application\Contact\LinkContactPersonInput;
use App\Party\Application\Contact\RestoreContactInput;
use App\Party\Application\Contact\UnlinkContactPersonInput;
use App\Party\Application\Contact\UpdateContactInput;
use App\Party\Application\CreateParty;
use App\Party\Application\LinkContactPerson;
use App\Party\Application\PartyDetails;
use App\Party\Application\PartyInput;
use App\Party\Application\PartyNotAllowed;
use App\Party\Application\PartyRefused;
use App\Party\Application\UnlinkContactPerson;
use App\Party\Application\UpdateParty;
use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyKind;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Tests\Party\Application\Query\ContactTestCase;

/**
 * The contact writes of the API and MCP (ADR 0032) called as the handlers call
 * them: what is sent is trimmed, a left-out field keeps its value, a duplicate
 * is refused by the field it matched, and a guest is refused before anything
 * else is looked at.
 */
final class ContactWritesTest extends ContactTestCase
{
    private ContactWrites $writes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writes = self::getContainer()->get(ContactWrites::class);
    }

    public function testCreateTrimsEveryValueAndUpperCasesTheCountries(): void
    {
        // Every value is as long as its field takes, in characters of two bytes, and padded on both sides.
        $email = str_repeat('a', 242).'@example.com';
        $id = $this->writes->create(new CreateContactInput(
            kind: 'company',
            displayName: ' '.str_repeat('č', 255).' ',
            street: ' '.str_repeat('š', 255).' ',
            city: ' '.str_repeat('ř', 128).' ',
            postalCode: ' '.str_repeat('ž', 32).' ',
            countryCode: ' cz ',
            registrationNumber: ' '.str_repeat('ý', 64).' ',
            registrationCountry: ' de ',
            vatId: ' ',
            email: ' '.$email.' ',
            phone: ' '.str_repeat('á', 64).' ',
            mobile: ' '.str_repeat('é', 64).' ',
            website: ' '.str_repeat('í', 255).' ',
            roles: ['customer'],
            privateNote: ' Pays late ',
        ));

        $party = $this->reloaded($id);
        self::assertSame(str_repeat('č', 255), $party->displayName());
        self::assertSame('DE', $party->registrationNumber()?->countryCode);
        self::assertSame(str_repeat('ý', 64), $party->registrationNumber()->toString());
        self::assertNotInstanceOf(VatId::class, $party->vatId());
        self::assertSame($email, $this->details()->generalEmail($party)?->address());
        self::assertSame(str_repeat('á', 64), $party->phone());
        self::assertSame(str_repeat('é', 64), $party->mobile());
        self::assertSame(str_repeat('í', 255), $party->website());
        self::assertSame('Pays late', $party->privateNote());
        self::assertEquals(new Address(str_repeat('š', 255), str_repeat('ř', 128), str_repeat('ž', 32), 'CZ'), $this->details()->billingAddress($party)?->address());
        self::assertSame([$id], $this->events('party.contact.created'));
    }

    public function testCreateRefusesAValueOneCharacterTooLong(): void
    {
        $this->assertRefused(PartyRefused::TOO_LONG, 'displayName', fn (): string => $this->writes->create($this->company(displayName: str_repeat('č', 256))));
        $this->assertRefused(PartyRefused::TOO_LONG, 'registrationNumber', fn (): string => $this->writes->create($this->company(registrationNumber: str_repeat('ý', 65), registrationCountry: 'DE')));
        // Too long is said before the country is looked at.
        $this->assertRefused(PartyRefused::TOO_LONG, 'registrationNumber', fn (): string => $this->writes->create($this->company(registrationNumber: str_repeat('ý', 65), registrationCountry: 'XX')));
        $this->assertRefused(PartyRefused::EMAIL, 'email', fn (): string => $this->writes->create($this->company(email: str_repeat('a', 243).'@example.com')));
        self::assertSame([], $this->events('party.contact.created'));
    }

    public function testTheRegistrationCountryIsTheBillingCountryWhenLeftOut(): void
    {
        $id = $this->writes->create($this->company(countryCode: 'de', registrationNumber: 'ABC 123'));

        self::assertEquals(new RegistrationNumber('DE', 'ABC 123'), $this->reloaded($id)->registrationNumber());
    }

    public function testAGuestIsRefusedBeforeTheRulesAreAsked(): void
    {
        $this->signIn($this->guest);

        $this->expectException(PartyNotAllowed::class);
        $this->writes->create($this->company(displayName: ''));
    }

    public function testAGuestCannotRunAnyWriteOfTheAddressBook(): void
    {
        $container = self::getContainer();
        $company = $this->contact('Alpha Example');
        $person = $this->contact('Petra Example', PartyKind::Person, number: null);
        $container->get(LinkContactPerson::class)->link($company, $person, '', false);
        $input = new PartyInput(PartyKind::Person, 'Guest Made', null, null, '', '', [], new Address('Example Street 1', 'Example City', '100 00', 'CZ'));

        $this->signIn($this->guest);
        $this->assertNotAllowed(static fn () => $container->get(CreateParty::class)->createFrom($input));
        $this->assertNotAllowed(static fn () => $container->get(UpdateParty::class)->update($person, $input));
        $this->assertNotAllowed(static fn () => $container->get(ArchiveParty::class)->archive([$person]));
        $this->assertNotAllowed(static fn () => $container->get(ArchiveParty::class)->restore([$person]));
        $this->assertNotAllowed(static fn () => $container->get(LinkContactPerson::class)->link($company, $person, '', false));
        $this->assertNotAllowed(static fn () => $container->get(UnlinkContactPerson::class)->unlink($company, $person));
        $this->signIn($this->owner);

        $this->em->clear();
        self::assertSame('Petra Example', $this->reloaded($person->id())->displayName());
        self::assertCount(1, $this->details()->contactLinks($this->reloaded($company->id())));
    }

    public function testAnUpdateTrimsWhatIsSentAndKeepsWhatIsLeftOut(): void
    {
        $party = $this->contact('Alpha Example', vatId: 'CZ12345695', email: 'alpha@example.com');

        $this->writes->update(new UpdateContactInput($party->id(), city: ' Other City ', countryCode: ' sk '));

        $party = $this->reloaded($party->id());
        self::assertSame('Alpha Example', $party->displayName());
        // The number keeps the country it was issued by, not the new one of the address.
        self::assertEquals(new RegistrationNumber('CZ', '12345679'), $party->registrationNumber());
        self::assertSame('CZ12345695', $party->vatId()?->toString());
        self::assertSame('alpha@example.com', $this->details()->generalEmail($party)?->address());
        self::assertSame('+420 000 000 000', $party->phone());
        self::assertEquals(new Address('Example Street 1', 'Other City', '100 00', 'SK'), $this->details()->billingAddress($party)?->address());
        self::assertSame([$party->id()], $this->events('party.contact.updated'));
    }

    public function testAnUpdateTakesTheRegistrationCountrySentOrTheBillingCountry(): void
    {
        $party = $this->contact('Alpha Example');

        $this->writes->update(new UpdateContactInput($party->id(), registrationNumber: 'XYZ 1', registrationCountry: ' at '));
        self::assertEquals(new RegistrationNumber('AT', 'XYZ 1'), $this->reloaded($party->id())->registrationNumber());

        // Null takes the country of the billing address.
        $this->writes->update(new UpdateContactInput($party->id(), countryCode: 'DE', registrationNumber: 'ABC 123', registrationCountry: null));
        self::assertEquals(new RegistrationNumber('DE', 'ABC 123'), $this->reloaded($party->id())->registrationNumber());
    }

    public function testANumberSentWithoutItsCountryTakesTheBillingCountry(): void
    {
        $party = $this->party(PartyKind::Company, new RegistrationNumber('DE', 'ABC 1'), null, 'AT');

        // Left out, the number keeps its own country.
        $this->writes->update(new UpdateContactInput($party->id(), phone: '+43 1'));
        self::assertEquals(new RegistrationNumber('DE', 'ABC 1'), $this->reloaded($party->id())->registrationNumber());

        $this->writes->update(new UpdateContactInput($party->id(), registrationNumber: 'XYZ 9'));
        self::assertEquals(new RegistrationNumber('AT', 'XYZ 9'), $this->reloaded($party->id())->registrationNumber());
    }

    public function testAnUpdateOfAContactWithoutIdentifiersOrEmail(): void
    {
        $person = $this->contact('Petra Example', PartyKind::Person, number: null);

        $this->writes->update(new UpdateContactInput($person->id(), phone: '+420 999 999 999'));

        $person = $this->reloaded($person->id());
        self::assertSame('+420 999 999 999', $person->phone());
        self::assertNull($person->registrationNumber());
        self::assertNull($this->details()->generalEmail($person));
    }

    public function testClearingTheNumberKeepsTheVatId(): void
    {
        $party = $this->contact('Alpha Example', vatId: 'CZ12345695');

        $this->writes->update(new UpdateContactInput($party->id(), registrationNumber: null));

        $party = $this->reloaded($party->id());
        self::assertNull($party->registrationNumber());
        self::assertSame('CZ12345695', $party->vatId()?->toString());
    }

    public function testAContactWithoutABillingAddressNeedsOne(): void
    {
        $party = $this->party(PartyKind::Person, null, null, null);

        $refused = $this->assertRefused(PartyRefused::REQUIRED, 'street', fn (): string => $this->writes->update(new UpdateContactInput($party->id(), phone: '+420 1')));
        self::assertSame('The field street needs a value.', $refused->getMessage());
    }

    public function testADuplicateIsRefusedByTheFieldItMatched(): void
    {
        $beta = $this->contact('Beta Example', number: '12345687', vatId: 'CZ12345695');

        $refused = $this->assertRefused(PartyRefused::DUPLICATE, 'vatId', fn (): string => $this->writes->create($this->company(registrationNumber: '', vatId: 'cz 12345695')));
        self::assertSame([$beta->id()], $refused->existingIds);

        $refused = $this->assertRefused(PartyRefused::DUPLICATE, 'registrationNumber', fn (): string => $this->writes->create($this->company(registrationNumber: '12345687', vatId: 'CZ12345695')));
        self::assertSame([$beta->id()], $refused->existingIds);
    }

    public function testAnArchivedContactIsADuplicateToo(): void
    {
        $beta = $this->contact('Beta Example', number: '12345687', archived: true);

        $refused = $this->assertRefused(PartyRefused::DUPLICATE, 'registrationNumber', fn (): string => $this->writes->create($this->company(registrationNumber: '12345687')));
        self::assertSame([$beta->id()], $refused->existingIds);
    }

    public function testAnUpdateToTheIdentifiersOfAnotherContactIsRefused(): void
    {
        $alpha = $this->party(PartyKind::Company, new RegistrationNumber('DE', 'ABC 1'), null, 'DE');
        $beta = $this->party(PartyKind::Company, new RegistrationNumber('AT', 'ABC 1'), VatId::fromString('DE123456789'), 'AT');
        $gamma = $this->contact('Gamma Example', number: '12345687', vatId: 'CZ12345695');

        // The same number, issued by the country of another contact.
        $refused = $this->assertRefused(PartyRefused::DUPLICATE, 'registrationNumber', fn (): string => $this->writes->update(new UpdateContactInput($alpha->id(), registrationCountry: 'AT')));
        self::assertSame([$beta->id()], $refused->existingIds);

        // The VAT id of another contact, the number kept.
        $refused = $this->assertRefused(PartyRefused::DUPLICATE, 'vatId', fn (): string => $this->writes->update(new UpdateContactInput($alpha->id(), vatId: 'CZ12345695')));
        self::assertSame([$gamma->id()], $refused->existingIds);

        // Every identifier changed, to those of another contact.
        $refused = $this->assertRefused(PartyRefused::DUPLICATE, 'vatId', fn (): string => $this->writes->update(new UpdateContactInput($alpha->id(), registrationNumber: 'XYZ 2', registrationCountry: 'AT', vatId: 'DE123456789')));
        self::assertSame([$beta->id()], $refused->existingIds);
        self::assertSame([], $this->events('party.contact.updated'));
    }

    public function testArchiveAndRestoreRecordTheirEvents(): void
    {
        $party = $this->contact('Alpha Example');

        $this->writes->archive(new ArchiveContactInput($party->id()));
        $this->writes->restore(new RestoreContactInput($party->id()));

        self::assertSame([$party->id()], $this->events('party.contact.archived'));
        self::assertSame([$party->id()], $this->events('party.contact.restored'));
    }

    public function testALinkTrimsThePositionAndTakesOnlyAPersonToACompany(): void
    {
        $company = $this->contact('Alpha Example');
        $petra = $this->contact('Petra Example', PartyKind::Person, number: null);
        $pavel = $this->contact('Pavel Example', PartyKind::Person, number: null);

        $this->writes->linkPerson(new LinkContactPersonInput($company->id(), $petra->id(), ' '.str_repeat('č', 100).' '));
        $links = $this->details()->contactLinks($company);
        self::assertCount(1, $links);
        self::assertSame(str_repeat('č', 100), $links[0]->position());
        self::assertFalse($links[0]->isPrimary());

        $this->assertRefused(PartyRefused::TOO_LONG, 'position', fn (): string => $this->writes->linkPerson(new LinkContactPersonInput($company->id(), $pavel->id(), str_repeat('č', 101))));
        // A person has no contact persons.
        $this->assertRefused(PartyRefused::PERSON, 'personId', fn (): string => $this->writes->linkPerson(new LinkContactPersonInput($petra->id(), $pavel->id())));

        // Only the pair asked for is unlinked.
        $this->assertRefused(PartyRefused::NOT_LINKED, 'personId', fn (): string => $this->writes->unlinkPerson(new UnlinkContactPersonInput($company->id(), $pavel->id())));
        self::assertCount(1, $this->details()->contactLinks($company));
        $this->writes->unlinkPerson(new UnlinkContactPersonInput($company->id(), $petra->id()));
        self::assertSame([], $this->details()->contactLinks($company));
    }

    /**
     * @param list<string> $roles
     */
    private function company(
        string $displayName = 'Alpha Example s.r.o.',
        string $countryCode = 'CZ',
        string $registrationNumber = '12345679',
        ?string $registrationCountry = null,
        string $vatId = '',
        string $email = '',
        array $roles = ['customer'],
    ): CreateContactInput {
        return new CreateContactInput('company', $displayName, 'Example Street 1', 'Example City', '100 00', $countryCode, $registrationNumber, $registrationCountry, $vatId, $email, roles: $roles);
    }

    /**
     * A contact whose identifiers and billing country the helper of the case does not set.
     */
    private function party(PartyKind $kind, ?RegistrationNumber $number, ?VatId $vatId, ?string $billingCountry): Party
    {
        $party = new Party($kind, 'Party Example', $number, $vatId, '');
        $this->em->persist($party);
        if (null !== $billingCountry) {
            $this->em->persist(new PartyAddress($party, AddressType::Billing, new Address('Example Street 1', 'Example City', '100 00', $billingCountry)));
        }
        $this->em->flush();

        return $party;
    }

    private function reloaded(string $id): Party
    {
        return $this->em->find(Party::class, $id) ?? self::fail('The contact is gone.');
    }

    private function details(): PartyDetails
    {
        return self::getContainer()->get(PartyDetails::class);
    }

    /**
     * @param \Closure(): mixed $write
     */
    private function assertRefused(string $reason, string $field, \Closure $write): PartyRefused
    {
        try {
            $write();
        } catch (PartyRefused $refused) {
            self::assertSame($reason, $refused->reason);
            self::assertSame($field, $refused->field);

            return $refused;
        }
        self::fail(\sprintf('The write was not refused with %s.', $reason));
    }

    /**
     * @param \Closure(): mixed $write
     */
    private function assertNotAllowed(\Closure $write): void
    {
        try {
            $write();
            self::fail('A guest wrote to the address book.');
        } catch (PartyNotAllowed) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @return list<string> the subjects of the events of one name, in the order they were recorded
     */
    private function events(string $name): array
    {
        $subjects = $this->em->getConnection()->fetchFirstColumn(
            'SELECT '.EventLogTable::SUBJECT_ID.' FROM '.EventLogTable::LOG.' WHERE '.EventLogTable::NAME.' = ? ORDER BY '.EventLogTable::ID,
            [$name],
        );

        return array_map(static fn (mixed $subject): string => \is_string($subject) ? $subject : '', $subjects);
    }
}
