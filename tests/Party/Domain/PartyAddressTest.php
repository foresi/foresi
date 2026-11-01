<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Domain;

use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\PartyAddress;
use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use PHPUnit\Framework\TestCase;

final class PartyAddressTest extends TestCase
{
    public function testExposesItsData(): void
    {
        $party = PartyFactory::company();
        $address = new PartyAddress($party, AddressType::Delivery, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), '  Warehouse ');

        self::assertSame($party, $address->party());
        self::assertSame(AddressType::Delivery, $address->type());
        self::assertSame('Example City', $address->address()->city);
        self::assertSame('Warehouse', $address->label());
        self::assertTrue(Id::isValid($address->id()));
    }

    public function testRelocatesAndRelabels(): void
    {
        $address = new PartyAddress(PartyFactory::company(), AddressType::Billing, new Address('Example Street 1', 'Example City', '100 00', 'CZ'));

        $address->relocate(new Address('Main 2', 'Other City', '200 00', 'DE'));
        $address->relabel(' Head office ');

        self::assertSame('Main 2', $address->address()->street);
        self::assertSame('Head office', $address->label());
    }

    public function testAdoptsTheTenantOfItsPartyAndHasNoneBeforeThat(): void
    {
        $tenant = TenantId::generate();
        $address = new PartyAddress(PartyFactory::company('Example Company', $tenant), AddressType::Billing, new Address('Example Street 1', 'Example City', '100 00', 'CZ'));

        self::assertTrue($tenant->equals($address->tenantId()));

        $this->expectException(\LogicException::class);
        new PartyAddress(PartyFactory::company(), AddressType::Billing, new Address('Example Street 1', 'Example City', '100 00', 'CZ'))->tenantId();
    }
}
