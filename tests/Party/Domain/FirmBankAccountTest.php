<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Domain;

use Foresi\Party\Domain\FirmBankAccount;
use Foresi\Party\Domain\Iban;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Id;
use PHPUnit\Framework\TestCase;

final class FirmBankAccountTest extends TestCase
{
    private const string IBAN = 'CZ6508000000192000145399';

    public function testExposesItsData(): void
    {
        $account = FirmBankAccount::open(Iban::fromString('cz65 0800 0000 1920 0014 5399'), Currency::EUR, ' Main ', 3);

        self::assertTrue(Id::isValid($account->id()));
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

    public function testALabelIsCountedInCharactersNotBytes(): void
    {
        $account = $this->account();

        $account->relabel(str_repeat('ř', 100));

        self::assertSame(str_repeat('ř', 100), $account->label());
    }

    public function testALongLabelIsRefusedWhenOpening(): void
    {
        $this->expectException(\DomainException::class);
        FirmBankAccount::open(Iban::fromString(self::IBAN), Currency::CZK, str_repeat('x', 101), 0);
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
        return FirmBankAccount::open(Iban::fromString(self::IBAN), Currency::CZK, '', 0);
    }
}
