<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Contacts\Infrastructure\Mcp;

use Foresi\Contacts\Infrastructure\Mcp\GetContactTool;
use Foresi\Contacts\Infrastructure\Mcp\SearchContactsTool;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\ContactLink;
use Foresi\Party\Domain\EmailPurpose;
use Foresi\Party\Domain\Iban;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyBankAccount;
use Foresi\Party\Domain\PartyEmail;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Infrastructure\Mcp\GetFirmTool;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Shared\Infrastructure\Mcp\ReadToolTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * foresi_search_contacts and foresi_get_contact answer what GET /api/v1/contacts
 * and /api/v1/contacts/{id} answer; foresi_get_firm answers the one firm. The
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
        self::assertSame(['query', 'role', 'archived', 'registration_number', 'vat_id', 'view_id', 'filter', 'limit', 'cursor'], self::argumentNames($tool));
        self::assertSame(['customer', 'supplier', 'partner', 'authority'], self::argument($tool, 'role')['enum']);
        self::assertSame('boolean', self::argument($tool, 'archived')['type']);
        self::assertSame('string', self::argument($tool, 'registration_number')['type']);
        self::assertSame('string', self::argument($tool, 'vat_id')['type']);
        self::assertSame(1, self::argument($tool, 'limit')['minimum']);
    }

    public function testTheGetAndTheFirmDescribeTheirArguments(): void
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

        $firm = $this->tool(GetFirmTool::class);
        self::assertSame('foresi_get_firm', $firm->name());
        self::assertSame('Get the firm', $firm->title());
        self::assertSame('contacts:read', $firm->scope());
        self::assertStringStartsWith('Returns the firm of this Foresi instance', $firm->description());
        self::assertEquals(['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false], $firm->inputSchema());
    }

    public function testTheContactIsAnsweredAsTheRestApiWritesIt(): void
    {
        $company = $this->flushed(function (): Party {
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
        self::assertSame(['items' => $rest, 'next_cursor' => $this->json()['next_cursor'], 'applied_view' => null], self::wire($search->data));
        self::assertIsArray($rest);
        self::assertSame(['Petra Example'], array_column($rest, 'name'));
        self::assertSame('1 contact, newest first; more match: call again with the same arguments and next_cursor as cursor.', $search->summary);

        $get = $this->call(GetContactTool::class, ['id' => $company->id()]);
        self::assertSame($this->rest('/api/v1/contacts/'.$company->id()), self::wire($get->data));
        self::assertIsArray($get->data['contact_persons']);
        self::assertCount(1, $get->data['contact_persons']);
        self::assertSame('The contact with its addresses, e-mails, bank accounts and contact persons.', $get->summary);
    }

    public function testEveryFilterNarrowsTheSearch(): void
    {
        [$alpha, $beta, $archived] = $this->flushed(fn (): array => [
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
        $ids = $this->flushed(fn (): array => array_map(fn (string $name): string => $this->contact($name)->id(), ['First Example', 'Second Example']));

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

    public function testAnUnknownContactIsNotFound(): void
    {
        self::assertSame([$this->customerId], self::itemIds($this->call(SearchContactsTool::class)));
        $none = $this->call(SearchContactsTool::class, ['query' => 'Foreign']);
        self::assertSame(['items' => [], 'next_cursor' => null, 'applied_view' => null], $none->data);
        self::assertSame('No contacts match.', $none->summary);
        foreach ([Id::generate(), 'not-an-id'] as $id) {
            $this->assertRefused('not_found', 'The organisation has no contact with this id. Find the id with foresi_search_contacts.', GetContactTool::class, ['id' => $id]);
        }
        $this->assertRefused('not_found', 'The organisation has no contact with this id. Find the id with foresi_search_contacts.', GetContactTool::class);
    }

    public function testTheFirmIsAnsweredAsTheRestApiWritesIt(): void
    {
        $output = $this->call(GetFirmTool::class);

        self::assertSame($this->rest('/api/v1/firm'), $output->data);
        self::assertSame([
            'id' => $this->firmId,
            'name' => 'Example Firm s.r.o.',
            'registration_country' => 'CZ',
            'registration_number' => '12345695',
            'vat_id' => 'CZ12345695',
            'vat_status' => 'registered',
            'vat_statuses' => [['id' => $this->firmId, 'status' => 'registered', 'period_length' => 'month', 'valid_from' => null]],
            'register_entry' => 'Example register, entry C 1',
            'address' => ['street' => 'Example Street 1', 'city' => 'Example City', 'postal_code' => '100 00', 'country_code' => 'CZ'],
        ], $output->data);
        self::assertSame('The firm of the instance.', $output->summary);
    }

    public function testAnInstanceWithoutAFirmSaysSo(): void
    {
        $this->flushed(function (): void {
            $firm = $this->em()->find(LegalEntity::class, $this->firmId);
            self::assertNotNull($firm);
            $this->em()->remove($firm);
        });

        $this->assertRefused('not_found', 'The instance has no firm yet: its first-run setup has not happened.', GetFirmTool::class);
    }

    public function testAGuestReadsNoContactsAndNoFirm(): void
    {
        $guest = $this->guest();

        $this->assertRefused('forbidden', 'Only the owners and members of the organisation read its contacts.', SearchContactsTool::class, [], $guest);
        $this->assertRefused('forbidden', 'Only the owners and members of the organisation read its contacts.', GetContactTool::class, ['id' => $this->customerId], $guest);
        $this->assertRefused('forbidden', 'Only the owners and members of the organisation read its firm.', GetFirmTool::class, [], $guest);
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
