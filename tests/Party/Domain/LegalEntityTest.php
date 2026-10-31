<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Domain;

use App\Party\Domain\Address;
use App\Party\Domain\InvalidIban;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Id;
use PHPUnit\Framework\TestCase;

final class LegalEntityTest extends TestCase
{
    public function testItHasAnId(): void
    {
        self::assertTrue(Id::isValid($this->firm()->id()));
    }

    public function testUpdateReplacesEverythingAndNormalisesTheAccounts(): void
    {
        $firm = $this->firm();
        $address = new Address('Other Street 2', 'Other City', '200 00', 'SK');

        $firm->update(' Other Firm ', new RegistrationNumber('SK', '123 456'), VatId::fromString('CZ12345695'), $address, VatStatus::Registered, 'Register entry 2', ['de89 3704 0044 0532 0130 00']);

        self::assertSame('Other Firm', $firm->name());
        self::assertSame('123 456', $firm->registrationNumber()->value);
        self::assertSame('CZ12345695', $firm->vatId()?->toString());
        self::assertSame($address, $firm->address());
        self::assertSame(VatStatus::Registered, $firm->vatStatus());
        self::assertSame('Register entry 2', $firm->registerEntry());
        self::assertSame(['DE89370400440532013000'], $firm->bankAccounts());
    }

    public function testUpdateRefusesAnEmptyName(): void
    {
        $this->expectException(\DomainException::class);

        $this->firm()->update(' ', new RegistrationNumber('CZ', '12345695'), null, $this->address(), VatStatus::NotRegistered, '', []);
    }

    public function testUpdateRefusesABadIban(): void
    {
        $this->expectException(InvalidIban::class);

        $this->firm()->update('Example Firm', new RegistrationNumber('CZ', '12345695'), null, $this->address(), VatStatus::NotRegistered, '', ['DE00']);
    }

    public function testArchivingKeepsTheFirstDate(): void
    {
        $firm = $this->firm();
        self::assertFalse($firm->isArchived());

        $firm->archive(new \DateTimeImmutable('2026-10-31 12:00:00'));
        $firm->archive(new \DateTimeImmutable('2026-11-30 12:00:00'));

        self::assertTrue($firm->isArchived());
        self::assertEquals(new \DateTimeImmutable('2026-10-31 12:00:00'), $firm->archivedAt());
    }

    public function testAnActiveFirmHoldsItsNumberHoweverItIsWritten(): void
    {
        $firm = new LegalEntity('Example Firm', new RegistrationNumber('DE', 'HRB 123'), null, $this->address(), VatStatus::NotRegistered, '', []);

        self::assertTrue($firm->holdsRegistrationNumber(new RegistrationNumber('DE', 'hrb-123')));
        self::assertFalse($firm->holdsRegistrationNumber(new RegistrationNumber('AT', 'HRB 123')));
        self::assertFalse($firm->holdsRegistrationNumber(new RegistrationNumber('DE', 'HRB 124')));
    }

    public function testAnArchivedFirmHoldsNoNumberEvenAfterAnUpdate(): void
    {
        $firm = $this->firm();
        $firm->archive(new \DateTimeImmutable('2026-10-31 12:00:00'));
        self::assertFalse($firm->holdsRegistrationNumber(new RegistrationNumber('CZ', '12345695')));

        $firm->update('Example Firm', new RegistrationNumber('CZ', '12345687'), null, $this->address(), VatStatus::NotRegistered, '', []);

        self::assertFalse($firm->holdsRegistrationNumber(new RegistrationNumber('CZ', '12345687')));
    }

    private function firm(): LegalEntity
    {
        return new LegalEntity('Example Firm', new RegistrationNumber('CZ', '12345695'), null, $this->address(), VatStatus::NotRegistered, '', []);
    }

    private function address(): Address
    {
        return new Address('Example Street 1', 'Example City', '100 00', 'CZ');
    }
}
