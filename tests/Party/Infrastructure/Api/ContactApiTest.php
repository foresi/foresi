<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Infrastructure\Api;

use Foresi\Identity\Domain\ApiScope;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\ContactLink;
use Foresi\Party\Domain\EmailPurpose;
use Foresi\Party\Domain\Iban;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyBankAccount;
use Foresi\Party\Domain\PartyEmail;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Infrastructure\Api\Cursor;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * GET /api/v1/contacts and /api/v1/contacts/{id}: the address book of the token's
 * organisation, narrowed by the query string and read by cursor. The customer of
 * the firm is in it from the start.
 */
final class ContactApiTest extends ApiTestCase
{
    public function testARowOfTheListIsWrittenAsTheAddressBookHoldsIt(): void
    {
        $contact = $this->flushed(fn (): Party => $this->contact('Alpha Example s.r.o.', vatId: 'CZ12345679', email: 'alpha@example.com'));

        $this->get('/api/v1/contacts', ['limit' => '1']);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        self::assertSame([
            'data' => [[
                'id' => $contact->id(),
                'kind' => 'company',
                'name' => 'Alpha Example s.r.o.',
                'roles' => ['customer', 'supplier'],
                'registration_country' => 'CZ',
                'registration_number' => '12345679',
                'vat_id' => 'CZ12345679',
                'email' => 'alpha@example.com',
                'billing_address' => ['street' => 'Example Street 1', 'city' => 'Example City', 'postal_code' => '100 00', 'country_code' => 'CZ'],
                'archived' => false,
            ]],
            'next_cursor' => Cursor::encode($contact->id()),
            'applied_view' => null,
        ], $this->json());
    }

    public function testARowWithoutAddressOrNumbersHasNulls(): void
    {
        $person = $this->flushed(function (): Party {
            $person = new Party(PartyKind::Person, 'Petra Example', null, null, '');
            $this->em()->persist($person);

            return $person;
        });

        $this->get('/api/v1/contacts', ['limit' => '1']);

        self::assertSame([
            'id' => $person->id(),
            'kind' => 'person',
            'name' => 'Petra Example',
            'roles' => [],
            'registration_country' => null,
            'registration_number' => null,
            'vat_id' => null,
            'email' => null,
            'billing_address' => null,
            'archived' => false,
        ], $this->at('data', 0));
    }

    public function testTheDetailHoldsAddressesEmailsAccountsAndContactPersons(): void
    {
        [$company, $person, $ids] = $this->flushed(function (): array {
            $em = $this->em();
            $company = $this->contact('Alpha Example s.r.o.', email: 'alpha@example.com');
            $company->changeMobile('+420 111 111 111');
            $company->changeWebsite('https://example.com');
            $delivery = new PartyAddress($company, AddressType::Delivery, new Address('Store Street 3', 'Store City', '300 00', 'CZ'), 'Store');
            $invoices = new PartyEmail($company, 'invoices@example.com', EmailPurpose::Invoices);
            $account = new PartyBankAccount($company, Iban::fromString(self::IBAN), 'GIBACZPX', 'Main', true);
            $person = new Party(PartyKind::Person, 'Petra Example', null, null, '');
            $link = new ContactLink($company, $person, 'Accountant', true);
            foreach ([$delivery, $invoices, $account, $person, $link] as $entity) {
                $em->persist($entity);
            }

            return [$company, $person, ['delivery' => $delivery->id(), 'invoices' => $invoices->id(), 'account' => $account->id(), 'link' => $link->id()]];
        });
        [$billing, $general] = $this->flushed(fn (): array => [
            $this->em()->getRepository(PartyAddress::class)->findOneBy(['type' => AddressType::Billing, 'party' => $company->id()])?->id(),
            $this->em()->getRepository(PartyEmail::class)->findOneBy(['purpose' => EmailPurpose::General, 'party' => $company->id()])?->id(),
        ]);

        $this->get('/api/v1/contacts/'.$company->id());

        self::assertResponseIsSuccessful();
        self::assertSame(['data' => [
            'id' => $company->id(),
            'kind' => 'company',
            'name' => 'Alpha Example s.r.o.',
            'roles' => ['customer', 'supplier'],
            'registration_country' => 'CZ',
            'registration_number' => '12345679',
            'vat_id' => null,
            'email' => 'alpha@example.com',
            'billing_address' => ['street' => 'Example Street 1', 'city' => 'Example City', 'postal_code' => '100 00', 'country_code' => 'CZ'],
            'archived' => false,
            'phone' => '+420 000 000 000',
            'mobile' => '+420 111 111 111',
            'website' => 'https://example.com',
            'unreliable_payer' => false,
            'addresses' => [
                ['id' => $billing, 'type' => 'billing', 'label' => '', 'street' => 'Example Street 1', 'city' => 'Example City', 'postal_code' => '100 00', 'country_code' => 'CZ'],
                ['id' => $ids['delivery'], 'type' => 'delivery', 'label' => 'Store', 'street' => 'Store Street 3', 'city' => 'Store City', 'postal_code' => '300 00', 'country_code' => 'CZ'],
            ],
            'emails' => [
                ['id' => $general, 'address' => 'alpha@example.com', 'purpose' => 'general'],
                ['id' => $ids['invoices'], 'address' => 'invoices@example.com', 'purpose' => 'invoices'],
            ],
            'bank_accounts' => [
                ['id' => $ids['account'], 'iban' => self::IBAN, 'bic' => 'GIBACZPX', 'label' => 'Main', 'primary' => true],
            ],
            'contact_persons' => [[
                'id' => $ids['link'],
                'company' => ['id' => $company->id(), 'name' => 'Alpha Example s.r.o.'],
                'person' => ['id' => $person->id(), 'name' => 'Petra Example'],
                'position' => 'Accountant',
                'primary' => true,
            ]],
        ]], $this->json());
    }

    public function testTheFiltersNarrowTheList(): void
    {
        [$alpha, $beta, $archived] = $this->flushed(fn (): array => [
            $this->contact('Alpha Example s.r.o.', roles: [PartyRole::Supplier], number: '12345679', vatId: 'CZ12345679'),
            $this->contact('Beta Example a.s.', roles: [PartyRole::Partner], number: '25596641'),
            $this->contact('Archived Example', archived: true),
        ]);

        $this->get('/api/v1/contacts', ['q' => ' Alpha ']);
        self::assertSame([$alpha->id()], $this->ids());
        $this->get('/api/v1/contacts', ['role' => 'partner']);
        self::assertSame([$beta->id()], $this->ids());
        $this->get('/api/v1/contacts', ['archived' => 'true']);
        self::assertSame([$archived->id()], $this->ids());
        $this->get('/api/v1/contacts', ['archived' => 'false']);
        self::assertSame([$beta->id(), $alpha->id(), $this->customerId], $this->ids());
        $this->get('/api/v1/contacts', ['registration_number' => '255 96 641']);
        self::assertSame([$beta->id()], $this->ids());
        $this->get('/api/v1/contacts', ['vat_id' => 'cz 12345679']);
        self::assertSame([$alpha->id()], $this->ids());
    }

    public function testASavedViewAndAFilterNarrowTheListLikeItsTabs(): void
    {
        [$alpha, $beta, $archived] = $this->flushed(fn (): array => [
            $this->contact('Alpha Example s.r.o.', roles: [PartyRole::Supplier], number: '12345679'),
            $this->contact('Beta Example a.s.', roles: [PartyRole::Partner], number: '25596641'),
            $this->contact('Archived Example', roles: [PartyRole::Supplier], archived: true),
        ]);

        $this->get('/api/v1/contacts', ['view' => 'party.contacts.suppliers']);
        self::assertSame([$alpha->id()], $this->ids());
        self::assertSame('party.contacts.suppliers', $this->at('applied_view', 'system_key'));
        // The archived state of the view decides it instead of the archived parameter.
        $this->get('/api/v1/contacts', ['view' => 'party.contacts.archived']);
        self::assertSame([$archived->id()], $this->ids());
        $this->get('/api/v1/contacts', ['filter' => '{"conditions": [{"field": "role", "op": "is_any_of", "value": ["partner", "supplier"]}]}']);
        self::assertSame([$beta->id(), $alpha->id()], $this->ids());
        self::assertNull($this->json()['applied_view']);
        $this->get('/api/v1/contacts', ['filter' => '{"conditions": [{"field": "role", "op": "is", "value": "supplier"}]}', 'view' => 'party.contacts.archived']);
        self::assertSame([$archived->id()], $this->ids());
        $this->get('/api/v1/contacts', ['filter' => '{"conditions": [{"field": "kind", "op": "is_not", "value": "company"}]}']);
        self::assertSame([], $this->ids());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function badFilters(): iterable
    {
        yield 'not JSON' => [['filter' => '{conditions'], 'filter'];
        yield 'an unknown field' => [['filter' => '{"conditions": [{"field": "colour", "op": "is", "value": "red"}]}'], 'filter.conditions[0].field'];
        yield 'an unknown role' => [['filter' => '{"conditions": [{"field": "role", "op": "is", "value": "friend"}]}'], 'filter.conditions[0].value'];
        yield 'a view of another list' => [['view' => 'invoicing.invoices.paid'], 'view'];
    }

    /**
     * @param array<string, string> $query
     */
    #[DataProvider('badFilters')]
    public function testABadFilterIsRefusedWithItsPath(array $query, string $parameter): void
    {
        $this->get('/api/v1/contacts', $query);

        $this->assertProblem(400, 'invalid_parameter', ['parameter' => $parameter]);
    }

    public function testAViewNeedsTheViewsScopeAndARefusalSaysWhatToSend(): void
    {
        $this->get('/api/v1/contacts', ['view' => 'party.contacts.suppliers'], $this->issued([ApiScope::from('contacts:read')]));
        $this->assertProblem(403, 'insufficient_scope');

        $this->get('/api/v1/contacts', ['filter' => '{conditions']);
        $this->assertProblem(400, 'invalid_parameter', ['detail' => 'filter is a JSON object, {"conditions": [{"field": …, "op": …, "value": …}]}.']);
        $this->get('/api/v1/contacts', ['filter' => '{"conditions": [{"field": "colour", "op": "is", "value": "red"}]}']);
        $detail = $this->at('detail');
        self::assertIsString($detail);
        self::assertStringEndsWith('. Find the fields and operators with GET /api/v1/lists or foresi_list_lists.', $detail);
        self::assertStringStartsNotWith('.', $detail);
    }

    public function testTheCursorPagesThroughTheListWithoutOverlap(): void
    {
        $ids = $this->flushed(fn (): array => array_map(fn (string $name): string => $this->contact($name)->id(), ['First Example', 'Second Example']));

        self::assertSame([[$ids[1], $ids[0]], [$this->customerId]], $this->pages('/api/v1/contacts', 2));
        self::assertSame([[$ids[1], $ids[0], $this->customerId]], $this->pages('/api/v1/contacts', 3));
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidParameters(): iterable
    {
        yield 'a cursor that is no id' => [['cursor' => Cursor::encode('not-an-id')], 'cursor'];
        yield 'a cursor that is not base64url' => [['cursor' => '***'], 'cursor'];
        yield 'a limit above the maximum' => [['limit' => '101'], 'limit'];
        yield 'a limit below one' => [['limit' => '0'], 'limit'];
        yield 'an unknown role' => [['role' => 'friend'], 'role'];
        yield 'an archive flag that is no boolean' => [['archived' => 'sometimes'], 'archived'];
    }

    /**
     * @param array<string, string> $query
     */
    #[DataProvider('invalidParameters')]
    public function testAParameterThatCannotBeUsedIsNamed(array $query, string $parameter): void
    {
        $this->get('/api/v1/contacts', $query);

        $this->assertProblem(400, 'invalid_parameter', ['parameter' => $parameter]);
    }

    public function testAnUnknownContactIsNotFound(): void
    {
        foreach ([Id::generate(), 'not-an-id'] as $id) {
            $this->get('/api/v1/contacts/'.$id);
            $this->assertProblem(404, 'not_found', ['detail' => 'No such contact.']);
        }
    }

    public function testATokenScopedToAnotherResourceReadsNothing(): void
    {
        $secret = $this->issued([ApiScope::from('invoices:write')]);

        $this->get('/api/v1/contacts', secret: $secret);
        $this->assertProblem(403, 'insufficient_scope');

        $this->get('/api/v1/contacts/'.$this->customerId, secret: $secret);
        $this->assertProblem(403, 'insufficient_scope');
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
