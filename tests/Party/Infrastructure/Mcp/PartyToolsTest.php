<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Infrastructure\Mcp;

use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\ContactLink;
use App\Party\Domain\EmailPurpose;
use App\Party\Domain\Iban;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyBankAccount;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Party\Infrastructure\Mcp\GetContactTool;
use App\Party\Infrastructure\Mcp\ListFirmsTool;
use App\Party\Infrastructure\Mcp\SearchContactsTool;
use App\Shared\Domain\Id;
use App\Tests\Shared\Infrastructure\Mcp\ReadToolTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * foresi_search_contacts and foresi_get_contact answer what GET /api/v1/contacts
 * and /api/v1/contacts/{id} answer; foresi_list_firms names the firms. The
 * customer of the firm is in the address book from the start.
 */
final class PartyToolsTest extends ReadToolTestCase
{
    public function testTheSearchDescribesItsArguments(): void
    {
        $tool = $this->tool(SearchContactsTool::class);

        self::assertSame('foresi_search_contacts', $tool->name());
        self::assertSame('Search contacts', $tool->title());
        self::assertStringStartsWith('Searches the address book of the organisation', $tool->description());
        self::assertStringContainsString('call again with the same arguments and next_cursor as cursor', $tool->description());
        $schema = $tool->inputSchema();
        self::assertSame('object', $schema['type']);
        self::assertFalse($schema['additionalProperties']);
        self::assertSame(['query', 'role', 'archived', 'registration_number', 'vat_id', 'limit', 'cursor'], self::argumentNames($tool));
        self::assertSame(['customer', 'supplier', 'partner', 'authority'], self::argument($tool, 'role')['enum']);
        self::assertSame('boolean', self::argument($tool, 'archived')['type']);
        self::assertSame('string', self::argument($tool, 'registration_number')['type']);
        self::assertSame('string', self::argument($tool, 'vat_id')['type']);
        self::assertSame(1, self::argument($tool, 'limit')['minimum']);
    }

    public function testTheGetAndTheFirmsDescribeTheirArguments(): void
    {
        $get = $this->tool(GetContactTool::class);
        self::assertSame('foresi_get_contact', $get->name());
        self::assertSame('Get a contact', $get->title());
        self::assertStringStartsWith('Returns one contact of the address book by its id', $get->description());
        self::assertSame([
            'type' => 'object',
            'properties' => ['id' => ['type' => 'string', 'format' => 'uuid', 'description' => 'The id of the contact, as foresi_search_contacts returns it.']],
            'required' => ['id'],
            'additionalProperties' => false,
        ], $get->inputSchema());

        $firms = $this->tool(ListFirmsTool::class);
        self::assertSame('foresi_list_firms', $firms->name());
        self::assertSame('List firms', $firms->title());
        self::assertStringStartsWith('Returns the firms of the organisation', $firms->description());
        self::assertEquals(['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false], $firms->inputSchema());
    }

    public function testTheContactIsAnsweredAsTheRestApiWritesIt(): void
    {
        $company = $this->inTenant($this->tenant, function (): Party {
            $em = $this->em();
            $company = $this->contact('Alpha Example s.r.o.', email: 'alpha@example.com');
            $person = new Party(PartyKind::Person, 'Petra Example', null, null, '');
            foreach ([
                new PartyAddress($company, AddressType::Delivery, new Address('Store Street 3', 'Store City', '300 00', 'CZ'), 'Store'),
                new PartyEmail($company, 'invoices@example.com', EmailPurpose::Invoices),
                new PartyBankAccount($company, Iban::fromString(self::IBAN), 'GIBACZPX', 'Main', true),
                $person,
                new ContactLink($company, $person, 'Accountant', true),
            ] as $entity) {
                $em->persist($entity);
            }

            return $company;
        });

        $search = $this->call(SearchContactsTool::class, ['limit' => 1]);
        $rest = $this->rest('/api/v1/contacts', ['limit' => '1']);
        self::assertSame(['items' => $rest, 'next_cursor' => $this->json()['next_cursor']], $search->data);
        self::assertIsArray($rest);
        self::assertSame(['Petra Example'], array_column($rest, 'name'));
        self::assertSame('1 contact, newest first; more match: call again with the same arguments and next_cursor as cursor.', $search->summary);

        $get = $this->call(GetContactTool::class, ['id' => $company->id()]);
        self::assertSame($this->rest('/api/v1/contacts/'.$company->id()), $get->data);
        self::assertIsArray($get->data['contact_persons']);
        self::assertCount(1, $get->data['contact_persons']);
        self::assertSame('The contact with its addresses, e-mails, bank accounts and contact persons.', $get->summary);
    }

    public function testEveryFilterNarrowsTheSearch(): void
    {
        [$alpha, $beta, $archived] = $this->inTenant($this->tenant, fn (): array => [
            $this->contact('Alpha Example s.r.o.', roles: [PartyRole::Supplier], number: '12345679', vatId: 'CZ12345679'),
            $this->contact('Beta Example a.s.', roles: [PartyRole::Partner], number: '25596641'),
            $this->contact('Archived Example', archived: true),
        ]);

        self::assertSame([$alpha->id()], self::itemIds($this->call(SearchContactsTool::class, ['query' => ' Alpha '])));
        self::assertSame([$beta->id()], self::itemIds($this->call(SearchContactsTool::class, ['role' => 'partner'])));
        self::assertSame([$archived->id()], self::itemIds($this->call(SearchContactsTool::class, ['archived' => true])));
        self::assertSame([$beta->id(), $alpha->id(), $this->customerId], self::itemIds($this->call(SearchContactsTool::class, ['archived' => false])));
        self::assertSame([$beta->id()], self::itemIds($this->call(SearchContactsTool::class, ['registration_number' => '255 96 641'])));
        self::assertSame([$alpha->id()], self::itemIds($this->call(SearchContactsTool::class, ['vat_id' => 'cz 12345679'])));
    }

    public function testTheCursorPagesThroughTheSearch(): void
    {
        $ids = $this->inTenant($this->tenant, fn (): array => array_map(fn (string $name): string => $this->contact($name)->id(), ['First Example', 'Second Example']));

        self::assertSame([[$ids[1], $ids[0]], [$this->customerId]], $this->toolPages(SearchContactsTool::class, 2));
        $last = $this->call(SearchContactsTool::class);
        self::assertSame([$ids[1], $ids[0], $this->customerId], self::itemIds($last));
        self::assertSame('3 contacts, newest first; no more match.', $last->summary);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidArguments(): iterable
    {
        yield 'an unknown role' => [['role' => 'friend'], 'The argument "role" cannot be used: The value you selected is not a valid choice.'];
        yield 'a limit below one' => [['limit' => 0], 'The argument "limit" cannot be used: This value should be between 1 and 100.'];
        yield 'a cursor no search gave' => [['cursor' => '***'], 'The argument "cursor" is not one a search gave. Pass next_cursor of the previous answer unchanged, or leave cursor out for the first page.'];
        yield 'an archive flag that is no boolean' => [['archived' => 'sometimes'], 'The argument "archived" must be true or false.'];
        yield 'a number that is no string' => [['registration_number' => 12345679], 'The argument "registration_number" must be a string.'];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('invalidArguments')]
    public function testAnArgumentThatCannotBeUsedIsRefusedByName(array $arguments, string $message): void
    {
        $this->assertRefused('invalid_argument', $message, SearchContactsTool::class, $arguments);
    }

    public function testAContactOfAnotherOrganisationIsNotFound(): void
    {
        $theirs = $this->inTenant($this->foreignTenant(), fn (): Party => $this->contact('Foreign Example'));

        self::assertSame([$this->customerId], self::itemIds($this->call(SearchContactsTool::class)));
        $none = $this->call(SearchContactsTool::class, ['query' => 'Foreign']);
        self::assertSame(['items' => [], 'next_cursor' => null], $none->data);
        self::assertSame('No contacts match.', $none->summary);
        foreach ([$theirs->id(), Id::generate(), 'not-an-id'] as $id) {
            $this->assertRefused('not_found', 'The organisation has no contact with this id. Find the id with foresi_search_contacts.', GetContactTool::class, ['id' => $id]);
        }
        $this->assertRefused('not_found', 'The organisation has no contact with this id. Find the id with foresi_search_contacts.', GetContactTool::class);
    }

    public function testTheFirmsAreListedByNameArchivedOnesToo(): void
    {
        [$other, $archived] = $this->inTenant($this->tenant, function (): array {
            [$other] = $this->firmWithCustomer('Alpha Firm s.r.o.', '25596641');
            [$archived] = $this->firmWithCustomer('Zulu Firm s.r.o.', '27082440');
            $this->em()->find(LegalEntity::class, $archived)?->archive(new \DateTimeImmutable('2026-11-01 10:00:00'));

            return [$other, $archived];
        });
        $this->inTenant($this->foreignTenant(), fn (): array => $this->firmWithCustomer('Foreign Firm s.r.o.'));

        $output = $this->call(ListFirmsTool::class);

        self::assertSame(['items' => [
            ['id' => $other, 'name' => 'Alpha Firm s.r.o.', 'archived' => false],
            ['id' => $this->firmId, 'name' => 'Example Firm s.r.o.', 'archived' => false],
            ['id' => $archived, 'name' => 'Zulu Firm s.r.o.', 'archived' => true],
        ]], $output->data);
        self::assertSame('3 firms, by name.', $output->summary);
    }

    public function testOneFirmIsSaidSo(): void
    {
        $output = $this->call(ListFirmsTool::class);

        self::assertSame(['items' => [['id' => $this->firmId, 'name' => 'Example Firm s.r.o.', 'archived' => false]]], $output->data);
        self::assertSame('1 firm.', $output->summary);
    }

    public function testAGuestReadsNoContactsAndNoFirms(): void
    {
        $guest = $this->guest();

        $this->assertRefused('forbidden', 'Only the owners and members of the organisation read its contacts.', SearchContactsTool::class, [], $guest);
        $this->assertRefused('forbidden', 'Only the owners and members of the organisation read its contacts.', GetContactTool::class, ['id' => $this->customerId], $guest);
        $this->assertRefused('forbidden', 'Only the owners and members of the organisation read its firms.', ListFirmsTool::class, [], $guest);
    }

    /**
     * @param list<PartyRole> $roles
     */
    private function contact(string $name, array $roles = [PartyRole::Customer, PartyRole::Supplier], ?string $number = '12345679', ?string $vatId = null, ?string $email = null, bool $archived = false): Party
    {
        $em = $this->em();
        $party = new Party(PartyKind::Company, $name, null === $number ? null : new RegistrationNumber('CZ', $number), null === $vatId ? null : VatId::fromString($vatId), '+420 000 000 000', $roles);
        if ($archived) {
            $party->archive(new \DateTimeImmutable('2026-11-01 10:00:00'));
        }
        $em->persist($party);
        $em->persist(new PartyAddress($party, AddressType::Billing, new Address('Example Street 1', 'Example City', '100 00', 'CZ')));
        if (null !== $email) {
            $em->persist(new PartyEmail($party, $email, EmailPurpose::General));
        }
        $em->flush();

        return $party;
    }
}
