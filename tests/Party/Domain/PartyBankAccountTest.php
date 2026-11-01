<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Domain;

use App\Party\Domain\Iban;
use App\Party\Domain\PartyBankAccount;
use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PartyBankAccountTest extends TestCase
{
    private const string IBAN = 'DE89370400440532013000';

    public function testExposesItsData(): void
    {
        $party = PartyFactory::company();
        $account = new PartyBankAccount($party, Iban::fromString(self::IBAN), 'cobadeff', ' Main ', true);

        self::assertSame($party, $account->party());
        self::assertSame(self::IBAN, $account->iban()->toString());
        self::assertSame('COBADEFF', $account->bic());
        self::assertSame('Main', $account->label());
        self::assertTrue($account->isPrimary());
        self::assertTrue(Id::isValid($account->id()));
    }

    public function testDefaultsToASecondaryAccountWithoutBic(): void
    {
        $account = new PartyBankAccount(PartyFactory::company(), Iban::fromString(self::IBAN));

        self::assertNull($account->bic());
        self::assertSame('', $account->label());
        self::assertFalse($account->isPrimary());
    }

    public function testPromotesAndDemotes(): void
    {
        $account = new PartyBankAccount(PartyFactory::company(), Iban::fromString(self::IBAN));

        $account->makePrimary();
        self::assertTrue($account->isPrimary());

        $account->demote();
        self::assertFalse($account->isPrimary());
    }

    #[DataProvider('validBics')]
    public function testAcceptsBicsOfEightOrElevenCharacters(string $bic, string $expected): void
    {
        self::assertSame($expected, new PartyBankAccount(PartyFactory::company(), Iban::fromString(self::IBAN), $bic)->bic());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validBics(): iterable
    {
        yield 'eight' => ['COBADEFF', 'COBADEFF'];
        yield 'eleven' => ['COBADEFFXXX', 'COBADEFFXXX'];
        yield 'lower case with spaces' => ['coba deff 123', 'COBADEFF123'];
    }

    #[DataProvider('invalidBics')]
    public function testRejectsOtherBics(string $bic): void
    {
        $this->expectException(\DomainException::class);

        new PartyBankAccount(PartyFactory::company(), Iban::fromString(self::IBAN), $bic);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBics(): iterable
    {
        yield 'empty' => [''];
        yield 'seven' => ['COBADEF'];
        yield 'nine' => ['COBADEFFX'];
        yield 'ten' => ['COBADEFFXX'];
        yield 'twelve' => ['COBADEFFXXXX'];
        yield 'digits in the bank code' => ['1OBADEFF'];
        yield 'punctuation' => ['COBADE-F'];
    }

    public function testAdoptsTheTenantOfItsParty(): void
    {
        $tenant = TenantId::generate();

        self::assertTrue($tenant->equals(new PartyBankAccount(PartyFactory::company('Example Company', $tenant), Iban::fromString(self::IBAN))->tenantId()));
    }

    public function testTenantIsUnavailableBeforePersist(): void
    {
        $this->expectException(\LogicException::class);

        new PartyBankAccount(PartyFactory::company(), Iban::fromString(self::IBAN))->tenantId();
    }
}
