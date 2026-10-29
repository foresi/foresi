<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Domain;

use App\Party\Domain\Address;
use App\Party\Domain\Iban;
use App\Party\Domain\InvalidIban;
use App\Party\Domain\InvalidRegistrationNumber;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Party\Domain\VatStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PartyTest extends TestCase
{
    public function testCompanyRequiresRegistrationNumberOrVatId(): void
    {
        $this->expectException(\DomainException::class);

        $this->party(PartyKind::Company, null, null);
    }

    public function testCompanyWithVatIdOnlyIsAccepted(): void
    {
        $party = $this->party(PartyKind::Company, null, VatId::fromString('DE123456789'));

        self::assertSame('Example Company', $party->displayName());
        self::assertTrue($party->isCustomer());
        self::assertFalse($party->isSupplier());
    }

    public function testPersonNeedsNoIdentifiers(): void
    {
        $party = $this->party(PartyKind::Person, null, null);

        self::assertSame(PartyKind::Person, $party->kind());
        self::assertNull($party->registrationNumber());
        self::assertNull($party->vatId());
    }

    public function testCompanyWithRegistrationNumberIsAccepted(): void
    {
        $number = new RegistrationNumber('DE', 'HRB 1');

        self::assertEquals($number, $this->party(PartyKind::Company, $number, null)->registrationNumber());
    }

    public function testDisplayNameIsRequired(): void
    {
        $this->expectException(\DomainException::class);

        new Party(PartyKind::Person, ' ', null, null, $this->address(), 'testing@example.com', '', true, false);
    }

    public function testLegalEntityExposesItsData(): void
    {
        $number = new RegistrationNumber('DE', 'HRB 1');
        $entity = new LegalEntity(
            'Example Company',
            $number,
            null,
            $this->address(),
            VatStatus::NotRegistered,
            'Register entry 1',
            ['de89 3704 0044 0532 0130 00'],
        );

        self::assertSame('Example Company', $entity->name());
        self::assertSame($number, $entity->registrationNumber());
        self::assertNull($entity->vatId());
        self::assertSame(VatStatus::NotRegistered, $entity->vatStatus());
        self::assertSame('Register entry 1', $entity->registerEntry());
        self::assertSame(['DE89370400440532013000'], $entity->bankAccounts());
        self::assertSame('Example City', $entity->address()->city);
    }

    public function testTenantIsUnavailableBeforePersist(): void
    {
        $this->expectException(\LogicException::class);

        new Party(PartyKind::Person, 'Test Person', null, null, $this->address(), 'testing@example.com', '', true, false)->tenantId();
    }

    public function testLegalEntityTenantIsUnavailableBeforePersist(): void
    {
        $this->expectException(\LogicException::class);

        new LegalEntity('Example Company', new RegistrationNumber('DE', '1'), null, $this->address(), VatStatus::Registered, '', [])->tenantId();
    }

    public function testBankAccountsAreStoredAsAList(): void
    {
        $entity = new LegalEntity(
            'Example Company',
            new RegistrationNumber('DE', 'HRB 1'),
            null,
            $this->address(),
            VatStatus::NotRegistered,
            '',
            [3 => 'DE89370400440532013000', 7 => 'GB82WEST12345698765432'],
        );

        self::assertSame([0, 1], array_keys($entity->bankAccounts()));
    }

    #[DataProvider('invalidRegistrationNumbers')]
    public function testRegistrationNumberInvariants(string $country, string $value): void
    {
        $this->expectException(InvalidRegistrationNumber::class);

        new RegistrationNumber($country, $value);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidRegistrationNumbers(): iterable
    {
        yield 'lowercase country' => ['cz', '1'];
        yield 'long country' => ['CZE', '1'];
        yield 'empty country' => ['', '1'];
        yield 'blank value' => ['DE', '  '];
        yield 'empty value' => ['DE', ''];
    }

    public function testLegalEntityRequiresAName(): void
    {
        $this->expectException(\DomainException::class);

        new LegalEntity(' ', new RegistrationNumber('DE', '1'), null, $this->address(), VatStatus::Registered, '', []);
    }

    public function testIbanIsNormalised(): void
    {
        self::assertSame('DE89370400440532013000', Iban::fromString('DE89 3704 0044 0532 0130 00')->toString());
    }

    #[DataProvider('invalidIbans')]
    public function testInvalidIbanRejected(string $bad): void
    {
        $this->expectException(InvalidIban::class);

        Iban::fromString($bad);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIbans(): iterable
    {
        foreach (['DE843704-0044053201300', '-DE27370400440532013000', 'DE47370400440532013000-', 'DE89370400440532013001', 'DE8937', '89DE370400440532013000', ''] as $bad) {
            yield '' === $bad ? 'empty' : $bad => [$bad];
        }
    }

    private function party(PartyKind $kind, ?RegistrationNumber $number, ?VatId $vatId): Party
    {
        return new Party($kind, 'Example Company', $number, $vatId, $this->address(), 'testing@example.com', '', true, false);
    }

    private function address(): Address
    {
        return new Address('Example Street 1', 'Example City', '100 00', 'CZ');
    }
}
