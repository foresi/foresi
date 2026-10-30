<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Domain;

use App\Party\Domain\ContactLink;
use App\Tenancy\Domain\TenantId;
use PHPUnit\Framework\TestCase;

final class ContactLinkTest extends TestCase
{
    public function testLinksAPersonToACompany(): void
    {
        $company = PartyFactory::company();
        $person = PartyFactory::person();

        $link = new ContactLink($company, $person, ' Accountant ', true);

        self::assertSame($company, $link->company());
        self::assertSame($person, $link->person());
        self::assertSame('Accountant', $link->position());
        self::assertTrue($link->isPrimary());
        self::assertNull($link->id());
    }

    public function testIsNotPrimaryByDefaultAndCanChange(): void
    {
        $link = new ContactLink(PartyFactory::company(), PartyFactory::person());

        self::assertFalse($link->isPrimary());
        self::assertSame('', $link->position());

        $link->markPrimary(true);
        $link->changePosition(' CFO ');

        self::assertTrue($link->isPrimary());
        self::assertSame('CFO', $link->position());
    }

    public function testRejectsCompanyToCompany(): void
    {
        $this->expectException(\DomainException::class);

        new ContactLink(PartyFactory::company('Example Company'), PartyFactory::company('Other Company'));
    }

    public function testRejectsPersonToPerson(): void
    {
        $this->expectException(\DomainException::class);

        new ContactLink(PartyFactory::person('First Person'), PartyFactory::person('Second Person'));
    }

    public function testRejectsAPersonLinkedToThemselves(): void
    {
        $person = PartyFactory::person();

        $this->expectException(\DomainException::class);

        new ContactLink($person, $person);
    }

    public function testRejectsACompanyAsTheContact(): void
    {
        $this->expectException(\DomainException::class);

        new ContactLink(PartyFactory::person(), PartyFactory::company());
    }

    public function testRejectsPartiesOfDifferentTenants(): void
    {
        $this->expectException(\DomainException::class);

        new ContactLink(PartyFactory::company('Example Company', TenantId::generate()), PartyFactory::person('Test Person', TenantId::generate()));
    }

    public function testAdoptsTheTenantOfWhicheverPartyHasOne(): void
    {
        $tenant = TenantId::generate();

        self::assertTrue($tenant->equals(new ContactLink(PartyFactory::company('Example Company', $tenant), PartyFactory::person('Test Person', $tenant))->tenantId()));
        self::assertTrue($tenant->equals(new ContactLink(PartyFactory::company('Example Company', $tenant), PartyFactory::person())->tenantId()));
        self::assertTrue($tenant->equals(new ContactLink(PartyFactory::company(), PartyFactory::person('Test Person', $tenant))->tenantId()));
    }

    public function testTenantIsUnavailableBeforePersist(): void
    {
        $this->expectException(\LogicException::class);

        new ContactLink(PartyFactory::company(), PartyFactory::person())->tenantId();
    }
}
