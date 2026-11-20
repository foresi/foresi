<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Domain;

use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Id;
use PHPUnit\Framework\TestCase;

final class LegalEntityTest extends TestCase
{
    public function testItHasAnId(): void
    {
        self::assertTrue(Id::isValid($this->firm()->id()));
    }

    public function testUpdateReplacesEverything(): void
    {
        $firm = $this->firm();
        $address = new Address('Other Street 2', 'Other City', '200 00', 'SK');

        $firm->update(' Other Firm ', new RegistrationNumber('SK', '123 456'), VatId::fromString('CZ12345695'), $address, VatStatus::Registered, 'Register entry 2');

        self::assertSame('Other Firm', $firm->name());
        self::assertSame('123 456', $firm->registrationNumber()->value);
        self::assertSame('CZ12345695', $firm->vatId()?->toString());
        self::assertSame($address, $firm->address());
        self::assertSame(VatStatus::Registered, $firm->vatStatus());
        self::assertSame('Register entry 2', $firm->registerEntry());
    }

    public function testUpdateRefusesAnEmptyName(): void
    {
        $this->expectException(\DomainException::class);

        $this->firm()->update(' ', new RegistrationNumber('CZ', '12345695'), null, $this->address(), VatStatus::NotRegistered, '');
    }

    private function firm(): LegalEntity
    {
        return new LegalEntity('Example Firm', new RegistrationNumber('CZ', '12345695'), null, $this->address(), VatStatus::NotRegistered, '');
    }

    private function address(): Address
    {
        return new Address('Example Street 1', 'Example City', '100 00', 'CZ');
    }
}
