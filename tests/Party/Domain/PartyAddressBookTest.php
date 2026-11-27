<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Domain;

use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\RegistryCheck;
use Foresi\Party\Domain\RegistryResult;
use Foresi\Party\Domain\RegistrySource;
use Foresi\Party\Domain\VatId;
use PHPUnit\Framework\TestCase;

final class PartyAddressBookTest extends TestCase
{
    public function testRolesMayBeEmptyForAPlainContact(): void
    {
        $party = new Party(PartyKind::Person, 'Test Person', null, null, '');

        self::assertSame([], $party->roles());
        self::assertFalse($party->hasRole(PartyRole::Customer));
    }

    public function testRolesStayAUniqueList(): void
    {
        $party = new Party(PartyKind::Person, 'Test Person', null, null, '', [PartyRole::Supplier, PartyRole::Customer, PartyRole::Supplier]);

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
        $party = new Party(PartyKind::Person, 'Test Person', null, null, '', [PartyRole::Customer, PartyRole::Supplier, PartyRole::Partner]);

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
        self::assertSame('12345695', $party->registrationNumber()?->value);
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

    public function testChangePhone(): void
    {
        $party = $this->company();

        $party->changePhone('+420111111111');

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
        $party = new Party(PartyKind::Person, 'Test Person', new RegistrationNumber('DE', '1'), null, '');

        $party->changeIdentifiers(null, null);

        self::assertNull($party->registrationNumber());
    }

    public function testNote(): void
    {
        $party = $this->company();

        self::assertSame('', $party->privateNote());

        $party->changePrivateNote('Pays late');

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
        self::assertSame('12345687', new RegistrationNumber('CZ', '1234 5687')->normalised());
        self::assertSame('HRB1A', new RegistrationNumber('DE', 'hrb-1 / a')->normalised());
        self::assertSame('ŽLUTÝ1', new RegistrationNumber('CZ', 'žlutý 1')->normalised());
    }

    public function testChangingTheVatIdClearsTheRegistryResultsOfTheOldOne(): void
    {
        $party = $this->checkedCompany();

        $party->changeIdentifiers(new RegistrationNumber('CZ', '12345695'), VatId::fromString('DE123456789'));

        self::assertNull($party->viesCheck());
        self::assertNull($party->unreliablePayerCheck());
        self::assertSame([], $party->publishedBankAccounts());
    }

    public function testRemovingTheVatIdClearsTheRegistryResults(): void
    {
        $party = $this->checkedCompany();

        $party->changeIdentifiers(new RegistrationNumber('CZ', '12345695'), null);

        self::assertNull($party->viesCheck());
        self::assertNull($party->unreliablePayerCheck());
        self::assertSame([], $party->publishedBankAccounts());
    }

    public function testKeepingTheVatIdKeepsTheRegistryResults(): void
    {
        $party = $this->checkedCompany();

        $party->changeIdentifiers(new RegistrationNumber('CZ', '99999994'), VatId::fromString('CZ12345695'));

        self::assertNotNull($party->viesCheck());
        self::assertNotNull($party->unreliablePayerCheck());
        self::assertSame(['123456789/0100'], $party->publishedBankAccounts());
    }

    private function checkedCompany(): Party
    {
        $party = new Party(PartyKind::Company, 'Example Company', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), '', [PartyRole::Customer]);
        $at = new \DateTimeImmutable('2026-10-30 10:00:00');
        $party->recordRegistryCheck(new RegistryCheck(RegistrySource::Vies, RegistryResult::Valid, $at));
        $party->recordRegistryCheck(new RegistryCheck(RegistrySource::UnreliablePayer, RegistryResult::Unreliable, $at));
        $party->recordPublishedBankAccounts(['123456789/0100']);

        return $party;
    }

    private function company(): Party
    {
        return new Party(PartyKind::Company, 'Example Company', new RegistrationNumber('CZ', '12345695'), null, '', [PartyRole::Customer]);
    }
}
