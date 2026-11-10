<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Application\Query;

use App\Party\Application\PartyNotAllowed;
use App\Party\Application\Query\ContactDetails;
use App\Party\Application\UnknownParty;
use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\ContactLink;
use App\Party\Domain\EmailPurpose;
use App\Party\Domain\Iban;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyBankAccount;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Shared\Domain\Id;

final class ContactDetailsTest extends ContactTestCase
{
    public function testAContactComesWithEverythingThatBelongsToIt(): void
    {
        $company = $this->contact('Alpha Example s.r.o.', roles: [PartyRole::Customer], number: '12345679', vatId: 'CZ12345679', email: 'info@example.com');
        $person = $this->contact('Pavel Example', PartyKind::Person, [], null);
        $delivery = new PartyAddress($company, AddressType::Delivery, new Address('Delivery Street 2', 'Delivery City', '200 00', 'CZ'), 'Warehouse');
        $invoices = new PartyEmail($company, 'billing@example.com', EmailPurpose::Invoices);
        $secondary = new PartyBankAccount($company, Iban::fromString('CZ6508000000192000145399'), 'GIBACZPX', 'Second', false);
        $primary = new PartyBankAccount($company, Iban::fromString('DE89370400440532013000'), null, 'Main', true);
        $link = new ContactLink($company, $person, 'Accountant', true);
        foreach ([$delivery, $invoices, $secondary, $primary, $link] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $detail = $this->details()->of($company->id());

        self::assertSame($company->id(), $detail->contact->id);
        self::assertSame('Alpha Example s.r.o.', $detail->contact->name);
        self::assertSame('info@example.com', $detail->contact->email);
        self::assertSame('CZ12345679', $detail->contact->vatId);
        self::assertSame('+420 000 000 000', $detail->phone);
        self::assertSame('', $detail->mobile);
        self::assertSame('', $detail->website);
        self::assertFalse($detail->unreliablePayer);
        self::assertSame([AddressType::Billing, AddressType::Delivery], array_map(static fn (\App\Party\Application\Query\ContactAddress $address): AddressType => $address->type, $detail->addresses));
        self::assertSame('Warehouse', $detail->addresses[1]->label);
        self::assertEquals(new Address('Delivery Street 2', 'Delivery City', '200 00', 'CZ'), $detail->addresses[1]->address);
        self::assertSame($delivery->id(), $detail->addresses[1]->id);
        self::assertSame(['info@example.com', 'billing@example.com'], array_map(static fn (\App\Party\Application\Query\ContactEmail $email): string => $email->address, $detail->emails));
        self::assertSame([EmailPurpose::General, EmailPurpose::Invoices], array_map(static fn (\App\Party\Application\Query\ContactEmail $email): EmailPurpose => $email->purpose, $detail->emails));
        self::assertSame([$primary->id(), $secondary->id()], array_map(static fn (\App\Party\Application\Query\ContactBankAccount $account): string => $account->id, $detail->bankAccounts));
        self::assertSame(['DE89370400440532013000', 'CZ6508000000192000145399'], array_map(static fn (\App\Party\Application\Query\ContactBankAccount $account): string => $account->iban, $detail->bankAccounts));
        self::assertSame([null, 'GIBACZPX'], array_map(static fn (\App\Party\Application\Query\ContactBankAccount $account): ?string => $account->bic, $detail->bankAccounts));
        self::assertSame(['Main', 'Second'], array_map(static fn (\App\Party\Application\Query\ContactBankAccount $account): string => $account->label, $detail->bankAccounts));
        self::assertSame([true, false], array_map(static fn (\App\Party\Application\Query\ContactBankAccount $account): bool => $account->primary, $detail->bankAccounts));
        self::assertCount(1, $detail->contactLinks);
        $contactLink = $detail->contactLinks[0];
        self::assertSame([$link->id(), $company->id(), 'Alpha Example s.r.o.', $person->id(), 'Pavel Example', 'Accountant', true], [$contactLink->id, $contactLink->companyId, $contactLink->companyName, $contactLink->personId, $contactLink->personName, $contactLink->position, $contactLink->primary]);
    }

    public function testThePersonSeesTheLinkFromItsSideToo(): void
    {
        $company = $this->contact('Alpha Example s.r.o.');
        $person = $this->contact('Pavel Example', PartyKind::Person, [], null);
        $this->em->persist(new ContactLink($company, $person));
        $this->em->flush();

        $links = $this->details()->of($person->id())->contactLinks;

        self::assertCount(1, $links);
        self::assertSame($company->id(), $links[0]->companyId);
        self::assertFalse($links[0]->primary);
    }

    public function testAnArchivedContactIsReadInTheTrashToo(): void
    {
        $party = $this->contact('Old Example', archived: true);

        self::assertTrue($this->details()->of($party->id())->contact->archived);
    }

    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function testAnUnknownContactAndOneOfAnotherTenantAreNotFound(): void
    {
        $foreign = $this->foreignContact('Foreign Example');

        foreach ([Id::generate(), $foreign->id(), 'not-an-id', '12', ''] as $id) {
            try {
                $this->details()->of($id);
                self::fail('Contact "'.$id.'" was found.');
            } catch (UnknownParty) {
            }
            try {
                $this->details()->party($id);
                self::fail('Contact "'.$id.'" was found.');
            } catch (UnknownParty) {
            }
        }
    }

    public function testGuestsAndNobodyAreRefusedBeforeAnythingIsLooked(): void
    {
        $party = $this->contact('Alpha Example');

        $this->assertRefusedForGuestAndNobody(fn (): \App\Party\Application\Query\ContactDetail => $this->details()->of($party->id()));
        $this->assertRefusedForGuestAndNobody(fn (): \App\Party\Domain\Party => $this->details()->party($party->id()));
        // Refused, not "not found", even for a contact that does not exist.
        $this->signIn($this->guest);
        $this->expectException(PartyNotAllowed::class);
        $this->details()->of(Id::generate());
    }

    public function testAMemberReadsTheContactItself(): void
    {
        $party = $this->contact('Alpha Example');
        $this->signIn($this->member);

        self::assertSame($party->id(), $this->details()->party($party->id())->id());
    }

    private function details(): ContactDetails
    {
        return self::getContainer()->get(ContactDetails::class);
    }
}
