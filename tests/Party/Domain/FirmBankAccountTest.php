<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Domain;

use App\Party\Domain\Address;
use App\Party\Domain\FirmBankAccount;
use App\Party\Domain\Iban;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Id;
use PHPUnit\Framework\TestCase;

final class FirmBankAccountTest extends TestCase
{
    private const string IBAN = 'CZ6508000000192000145399';

    public function testExposesItsData(): void
    {
        $firm = $this->firm();
        $account = FirmBankAccount::open($firm, Iban::fromString('cz65 0800 0000 1920 0014 5399'), Currency::EUR, ' Main ', 3);

        self::assertTrue(Id::isValid($account->id()));
        self::assertSame($firm, $account->firm());
        self::assertSame($firm, $account->auditRoot());
        self::assertSame(self::IBAN, $account->iban());
        self::assertSame(Currency::EUR, $account->currency());
        self::assertSame('Main', $account->label());
        self::assertSame(3, $account->position());
        self::assertFalse($account->isPrimary());
        self::assertFalse($account->isArchived());
        self::assertNull($account->archivedAt());
    }

    public function testRelabelsAndChangesItsCurrency(): void
    {
        $account = $this->account();

        $account->relabel('  Savings ');
        $account->changeCurrency(Currency::USD);

        self::assertSame('Savings', $account->label());
        self::assertSame(Currency::USD, $account->currency());
    }

    public function testALabelHasAtMostAHundredCharacters(): void
    {
        $account = $this->account();
        $account->relabel(str_repeat('x', 100));
        self::assertSame(100, mb_strlen($account->label()));

        $this->expectException(\DomainException::class);
        $account->relabel(str_repeat('x', 101));
    }

    public function testALongLabelIsRefusedWhenOpening(): void
    {
        $this->expectException(\DomainException::class);
        FirmBankAccount::open($this->firm(), Iban::fromString(self::IBAN), Currency::CZK, str_repeat('x', 101), 0);
    }

    public function testIsMarkedAndUnmarkedPrimary(): void
    {
        $account = $this->account();

        $account->markPrimary(true);
        self::assertTrue($account->isPrimary());

        $account->markPrimary(false);
        self::assertFalse($account->isPrimary());
    }

    public function testArchivingDropsPrimaryAndKeepsTheFirstDate(): void
    {
        $account = $this->account();
        $account->markPrimary(true);

        $account->archive(new \DateTimeImmutable('2026-11-02 10:00'));
        $account->archive(new \DateTimeImmutable('2026-11-03 10:00'));

        self::assertTrue($account->isArchived());
        self::assertFalse($account->isPrimary());
        self::assertEquals(new \DateTimeImmutable('2026-11-02 10:00'), $account->archivedAt());
    }

    public function testAnArchivedAccountCannotBePrimary(): void
    {
        $account = $this->account();
        $account->archive(new \DateTimeImmutable('2026-11-02'));
        $account->markPrimary(false);

        $this->expectException(\DomainException::class);
        $account->markPrimary(true);
    }

    public function testRestoringMakesItActiveAgain(): void
    {
        $account = $this->account();
        $account->archive(new \DateTimeImmutable('2026-11-02'));

        $account->restore();

        self::assertFalse($account->isArchived());
        self::assertNull($account->archivedAt());
    }

    private function account(): FirmBankAccount
    {
        return FirmBankAccount::open($this->firm(), Iban::fromString(self::IBAN), Currency::CZK, '', 0);
    }

    private function firm(): LegalEntity
    {
        return new LegalEntity('Example Firm', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
    }
}
