<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Domain;

use App\Party\Domain\EmailPurpose;
use App\Party\Domain\PartyEmail;
use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PartyEmailTest extends TestCase
{
    public function testExposesItsDataAndTrims(): void
    {
        $party = PartyFactory::company();
        $email = new PartyEmail($party, ' invoices@example.com ', EmailPurpose::Invoices);

        self::assertSame($party, $email->party());
        self::assertSame('invoices@example.com', $email->address());
        self::assertSame(EmailPurpose::Invoices, $email->purpose());
        self::assertTrue(Id::isValid($email->id()));
    }

    public function testTheComparisonKeyIgnoresCaseAndSurroundingSpace(): void
    {
        self::assertSame('jana.ěščř@example.com', PartyEmail::key(' Jana.ĚŠČŘ@Example.COM '));

        $email = new PartyEmail(PartyFactory::company(), 'Info@Example.com', EmailPurpose::General);
        self::assertSame('info@example.com', new \ReflectionProperty($email, 'addressKey')->getValue($email));
        $email->change('New@Example.com', EmailPurpose::General);
        self::assertSame('new@example.com', new \ReflectionProperty($email, 'addressKey')->getValue($email));
    }

    public function testChanges(): void
    {
        $email = new PartyEmail(PartyFactory::company(), 'testing@example.com', EmailPurpose::General);

        $email->change('copy@example.com', EmailPurpose::Copy);

        self::assertSame('copy@example.com', $email->address());
        self::assertSame(EmailPurpose::Copy, $email->purpose());
    }

    public function testChangeValidatesToo(): void
    {
        $email = new PartyEmail(PartyFactory::company(), 'testing@example.com', EmailPurpose::General);

        $this->expectException(\DomainException::class);
        $email->change('nope', EmailPurpose::General);
    }

    public function testAddressOfExactlyTheMaximumLengthIsAccepted(): void
    {
        $address = str_repeat('a', 254 - \strlen('@example.com')).'@example.com';

        self::assertSame(254, \strlen(new PartyEmail(PartyFactory::company(), $address, EmailPurpose::General)->address()));
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidAddresses(string $address): void
    {
        $this->expectException(\DomainException::class);

        new PartyEmail(PartyFactory::company(), $address, EmailPurpose::General);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield 'no at' => ['testing.example.com'];
        yield 'no local part' => ['@example.com'];
        yield 'no domain' => ['testing@'];
        yield 'no dot in domain' => ['testing@example'];
        yield 'two ats' => ['a@b@example.com'];
        yield 'space inside' => ['te sting@example.com'];
        yield 'text after the address' => ['testing@example.com extra'];
        yield 'dot at the end' => ['testing@example.'];
        yield 'too long' => [str_repeat('a', 243).'@example.com'];
    }

    public function testAdoptsTheTenantOfItsParty(): void
    {
        $tenant = TenantId::generate();

        self::assertTrue($tenant->equals(new PartyEmail(PartyFactory::company('Example Company', $tenant), 'testing@example.com', EmailPurpose::General)->tenantId()));
    }

    public function testTenantIsUnavailableBeforePersist(): void
    {
        $this->expectException(\LogicException::class);

        new PartyEmail(PartyFactory::company(), 'testing@example.com', EmailPurpose::General)->tenantId();
    }
}
