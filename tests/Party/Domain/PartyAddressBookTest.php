<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Domain;

use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\InvoicingDefaults;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\RegistryCheck;
use App\Party\Domain\RegistryResult;
use App\Party\Domain\RegistrySource;
use App\Party\Domain\VatId;
use App\Shared\Domain\Currency;
use PHPUnit\Framework\TestCase;

final class PartyAddressBookTest extends TestCase
{
    public function testRolesMayBeEmptyForAPlainContact(): void
    {
        $party = new Party(PartyKind::Person, 'Test Person', null, null, 'testing@example.com', '');

        self::assertSame([], $party->roles());
        self::assertFalse($party->hasRole(PartyRole::Customer));
    }

    public function testRolesStayAUniqueList(): void
    {
        $party = new Party(PartyKind::Person, 'Test Person', null, null, 'testing@example.com', '', [PartyRole::Supplier, PartyRole::Customer, PartyRole::Supplier]);

        self::assertSame([PartyRole::Supplier, PartyRole::Customer], $party->roles());
        self::assertTrue($party->hasRole(PartyRole::Customer));
        self::assertFalse($party->hasRole(PartyRole::Authority));

        $party->assignRoles([PartyRole::Authority, PartyRole::Authority]);

        self::assertSame([PartyRole::Authority], $party->roles());

        $party->assignRoles([]);

        self::assertSame([], $party->roles());
    }

    public function testRolesIsAListEvenAfterReplacingTheRoles(): void
    {
        $party = new Party(PartyKind::Person, 'Test Person', null, null, 'testing@example.com', '', [PartyRole::Customer, PartyRole::Supplier, PartyRole::Partner]);

        $party->assignRoles([PartyRole::Partner, PartyRole::Partner, PartyRole::Customer]);

        self::assertSame([0, 1], array_keys($party->roles()));
        self::assertSame([PartyRole::Partner, PartyRole::Customer], $party->roles());
    }

    public function testArchivedPartyKeepsItsDataAndCanBeRestored(): void
    {
        $party = $this->company();
        $at = new \DateTimeImmutable('2026-10-30 10:00:00');

        self::assertFalse($party->isArchived());
        self::assertNull($party->archivedAt());

        $party->archive($at);

        self::assertTrue($party->isArchived());
        self::assertSame($at, $party->archivedAt());
        self::assertSame('Example Company', $party->displayName());
        self::assertSame('12345687', $party->registrationNumber()?->value);
        self::assertSame([PartyRole::Customer], $party->roles());

        $party->restore();

        self::assertFalse($party->isArchived());
        self::assertNull($party->archivedAt());
    }

    public function testArchivingTwiceKeepsTheFirstMoment(): void
    {
        $party = $this->company();
        $first = new \DateTimeImmutable('2026-10-30 10:00:00');

        $party->archive($first);
        $party->archive(new \DateTimeImmutable('2026-11-01 10:00:00'));

        self::assertSame($first, $party->archivedAt());
    }

    public function testRenameTrimsAndRequiresAName(): void
    {
        $party = $this->company();

        $party->rename('  Renamed Company ');

        self::assertSame('Renamed Company', $party->displayName());

        $this->expectException(\DomainException::class);
        $party->rename(' ');
    }

    public function testChangeContact(): void
    {
        $party = $this->company();

        $party->changeContact('new@example.com', '+420111111111');

        self::assertSame('new@example.com', $party->email());
        self::assertSame('+420111111111', $party->phone());
    }

    public function testChangeIdentifiersKeepsTheCompanyInvariant(): void
    {
        $party = $this->company();

        $party->changeIdentifiers(null, VatId::fromString('DE123456789'));

        self::assertNull($party->registrationNumber());
        self::assertSame('DE123456789', $party->vatId()?->toString());

        $party->changeIdentifiers(new RegistrationNumber('DE', 'HRB 1'), null);

        self::assertSame('HRB 1', $party->registrationNumber()?->value);
        self::assertNull($party->vatId());

        $this->expectException(\DomainException::class);
        $party->changeIdentifiers(null, null);
    }

    public function testChangeIdentifiersOfAPersonMayClearThem(): void
    {
        $party = new Party(PartyKind::Person, 'Test Person', new RegistrationNumber('DE', '1'), null, 'testing@example.com', '');

        $party->changeIdentifiers(null, null);

        self::assertNull($party->registrationNumber());
    }

    public function testNoteAndInvoicingDefaults(): void
    {
        $party = $this->company();

        self::assertSame('', $party->privateNote());
        self::assertEquals(InvoicingDefaults::standard(), $party->invoicingDefaults());

        $defaults = new InvoicingDefaults(30, Currency::EUR, DocumentLanguage::English, PaymentMethod::Card, true);
        $party->changeInvoicingDefaults($defaults);
        $party->changePrivateNote('Pays late');

        self::assertSame($defaults, $party->invoicingDefaults());
        self::assertSame('Pays late', $party->privateNote());
    }

    public function testRegistryChecksAreStoredPerSource(): void
    {
        $party = $this->company();
        $vies = new RegistryCheck(RegistrySource::Vies, RegistryResult::Valid, new \DateTimeImmutable('2026-10-30 10:00:00'));
        $payer = new RegistryCheck(RegistrySource::UnreliablePayer, RegistryResult::Reliable, new \DateTimeImmutable('2026-10-30 11:00:00'));

        self::assertNull($party->viesCheck());
        self::assertNull($party->unreliablePayerCheck());

        $party->recordRegistryCheck($vies);

        self::assertEquals($vies, $party->viesCheck());
        self::assertNull($party->unreliablePayerCheck());

        $party->recordRegistryCheck($payer);

        self::assertEquals($vies, $party->viesCheck());
        self::assertEquals($payer, $party->unreliablePayerCheck());
    }

    public function testRegistryNumberComparisonFormIgnoresSpacesAndCase(): void
    {
        self::assertSame('12345679', new RegistrationNumber('CZ', '1234 5679')->normalised());
        self::assertSame('HRB1A', new RegistrationNumber('DE', 'hrb-1 / a')->normalised());
    }

    private function company(): Party
    {
        return new Party(PartyKind::Company, 'Example Company', new RegistrationNumber('CZ', '12345687'), null, 'testing@example.com', '', [PartyRole::Customer]);
    }
}
