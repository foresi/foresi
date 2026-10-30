<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Application;

use App\Party\Application\CompanyRegistry;
use App\Party\Application\RefreshRegistryChecks;
use App\Party\Application\VatPayerReliability;
use App\Party\Application\VatRegistry;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\RegistryCheck;
use App\Party\Domain\RegistryResult;
use App\Party\Domain\VatId;
use App\Tests\Party\Domain\PartyFactory;
use App\Tests\Support\Registry\FakeCompanyRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RegistryCheckFlowTest extends KernelTestCase
{
    public function testChecksAreStoredOnThePartyWithTheirTime(): void
    {
        $party = new Party(PartyKind::Company, 'Example Company s.r.o.', null, VatId::fromString('CZ12345687'), '');

        $this->refresh()->refresh($party);

        self::assertSame(RegistryResult::Valid, $party->viesCheck()?->result);
        self::assertSame(RegistryResult::Unreliable, $party->unreliablePayerCheck()?->result);
    }

    public function testUnreachableRegistersAreRecordedAsUnavailableAndNothingThrows(): void
    {
        $party = new Party(PartyKind::Company, 'Example Company s.r.o.', null, VatId::fromString('CZ99999999'), '');

        $this->refresh()->refresh($party);

        self::assertSame(RegistryResult::Unavailable, $party->viesCheck()?->result);
        self::assertSame(RegistryResult::Unavailable, $party->unreliablePayerCheck()?->result);
    }

    public function testPartyWithoutVatIdIsLeftAlone(): void
    {
        $party = PartyFactory::person();

        $this->refresh()->refresh($party);

        self::assertNotInstanceOf(RegistryCheck::class, $party->viesCheck());
        self::assertNotInstanceOf(RegistryCheck::class, $party->unreliablePayerCheck());
    }

    public function testForeignVatIdGetsNoUnreliablePayerCheck(): void
    {
        $party = new Party(PartyKind::Company, 'Example GmbH', null, VatId::fromString('DE123456789'), '');

        $this->refresh()->refresh($party);

        self::assertSame(RegistryResult::Invalid, $party->viesCheck()?->result);
        self::assertNotInstanceOf(RegistryCheck::class, $party->unreliablePayerCheck());
    }

    public function testTestEnvironmentUsesFixtureBackedFakesInsteadOfLiveRegisters(): void
    {
        $registry = $this->service(CompanyRegistry::class);
        self::assertInstanceOf(CompanyRegistry::class, $registry);
        self::assertSame(FakeCompanyRegistry::class, get_debug_type($registry));

        $record = $registry->lookupByRegistrationNumber(new RegistrationNumber('CZ', '12345687'));
        self::assertSame('Example Company s.r.o.', $record?->name);
        self::assertNull($registry->lookupByRegistrationNumber(new RegistrationNumber('CZ', '12345695')));
        $accounts = self::getContainer()->get(VatPayerReliability::class)->check(VatId::fromString('CZ12345687'))->publishedBankAccounts;
        self::assertSame(['2000000011/0800', '19-2000000007/0800', 'CZ7308000000002000000007'], $accounts);
    }

    public function testEveryRegistryResultHasALabelInBothLanguages(): void
    {
        $translator = self::getContainer()->get('translator');

        foreach (RegistryResult::cases() as $case) {
            foreach (['cs', 'en'] as $locale) {
                $key = 'registry.result.'.$case->value;
                self::assertNotSame($key, $translator->trans($key, [], null, $locale), $key.' is missing in '.$locale);
            }
        }
    }

    private function service(string $id): mixed
    {
        return self::getContainer()->get($id);
    }

    private function refresh(): RefreshRegistryChecks
    {
        return new RefreshRegistryChecks(
            self::getContainer()->get(VatRegistry::class),
            self::getContainer()->get(VatPayerReliability::class),
        );
    }
}
