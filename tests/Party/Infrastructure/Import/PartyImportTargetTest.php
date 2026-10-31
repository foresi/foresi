<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Infrastructure\Import;

use App\Audit\Application\AuditCorrelation;
use App\Audit\Domain\AuditEntry;
use App\Import\Application\Target\RecordMatch;
use App\Import\Application\Target\RecordSnapshot;
use App\Import\Application\Target\TargetRegistry;
use App\Import\Domain\BlankCells;
use App\Import\Domain\FieldDefinition;
use App\Import\Domain\FieldType;
use App\Import\Domain\NormalisationOptions;
use App\Import\Domain\RowError;
use App\Import\Domain\ValueNormaliser;
use App\Party\Application\PartyDetails;
use App\Party\Application\PartyQuery;
use App\Party\Application\RecheckRegistry;
use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\ContactLink;
use App\Party\Domain\EmailPurpose;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyBankAccount;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\RegistrationNumber;
use App\Party\Infrastructure\Import\PartyImportTarget;
use App\Shared\Domain\Currency;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class PartyImportTargetTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private const string CORRELATION = 'import:1';

    private EntityManagerInterface $em;
    private PartyImportTarget $target;
    private TenantContext $tenants;

    protected function setUp(): void
    {
        self::mockTime('2026-10-01 10:00:00');
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $target = self::getContainer()->get(TargetRegistry::class)->get(PartyImportTarget::KEY);
        self::assertInstanceOf(PartyImportTarget::class, $target);
        $this->target = $target;
        $this->tenants = self::getContainer()->get(TenantContext::class);
        $tenant = TenantId::generate();
        $this->tenants->switchTo($tenant);
    }

    public function testTheTargetDescribesItsFieldsAndMatchKeys(): void
    {
        self::assertSame('party', $this->target->key());
        self::assertSame('import.target.party', $this->target->labelKey());
        self::assertSame(['id', 'registration_number', 'vat_id', 'email'], $this->target->matchKeys());
        $fields = $this->fields();
        self::assertTrue($fields['display_name']->required);
        self::assertSame(['display_name'], array_keys(array_filter($fields, static fn (FieldDefinition $field): bool => $field->required)));
        self::assertSame('import.field.party.billing_city', $fields['billing_city']->labelKey);
        self::assertSame(FieldType::EmailList, $fields['email']->type);
        self::assertSame(['person', 'sole_trader', 'company'], $fields['kind']->choices);
        self::assertSame(['CZK', 'EUR', 'USD'], $fields['currency']->choices);
        self::assertSame(['bank_transfer', 'cash', 'card'], $fields['payment_method']->choices);
        self::assertSame(['customer', 'supplier', 'partner', 'authority'], $fields['roles']->choices);
        foreach (['ic', 'ico', 'dic', 'nazev', 'nazev firmy', 'ulice', 'mesto', 'psc', 'stat', 'e mail', 'telefon', 'splatnost'] as $alias) {
            self::assertNotEmpty(array_filter($fields, static fn (FieldDefinition $field): bool => \in_array($alias, $field->aliases, true)), $alias);
        }
        self::assertSame(
            ['billing' => 4, 'delivery' => 4, 'bank' => 4, 'contact' => 7],
            array_count_values(array_filter(array_map(static fn (FieldDefinition $field): string => $field->group, $fields), static fn (string $group): bool => '' !== $group)),
        );
        // Every field has a label in both languages.
        $translator = self::getContainer()->get('translator');
        foreach ($fields as $field) {
            foreach (['cs', 'en'] as $locale) {
                self::assertNotSame($field->labelKey, $translator->trans($field->labelKey, [], 'messages', $locale), $field->key.' '.$locale);
            }
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, callable(Party, PartyDetails): mixed, mixed}>
     */
    public static function fieldValues(): iterable
    {
        yield 'name' => [['display_name' => 'Example Company s.r.o.'], static fn (Party $p): string => $p->displayName(), 'Example Company s.r.o.'];
        yield 'kind' => [['kind' => 'sole_trader'], static fn (Party $p): PartyKind => $p->kind(), PartyKind::SoleTrader];
        yield 'company by registration number' => [[], static fn (Party $p): PartyKind => $p->kind(), PartyKind::Company];
        yield 'company by VAT id' => [['registration_number' => null, 'vat_id' => 'CZ12345687'], static fn (Party $p): PartyKind => $p->kind(), PartyKind::Company];
        yield 'person without identifiers' => [['registration_number' => null], static fn (Party $p): PartyKind => $p->kind(), PartyKind::Person];
        yield 'person named as the contact' => [['registration_number' => null, 'vat_id' => 'CZ8501011234', 'display_name' => 'Jan Example', 'contact_first_name' => 'Jan', 'contact_last_name' => 'Example'], static fn (Party $p, PartyDetails $d): array => [$p->kind(), \count($d->contactLinks($p))], [PartyKind::Person, 0]];
        yield 'name from the contact' => [['registration_number' => null, 'display_name' => null, 'contact_first_name' => 'Jan', 'contact_last_name' => 'Example'], static fn (Party $p): array => [$p->displayName(), $p->kind()], ['Jan Example', PartyKind::Person]];
        yield 'registration number' => [['registration_number' => '12345687'], static fn (Party $p): ?string => $p->registrationNumber()?->value, '12345687'];
        yield 'registration number padded' => [['registration_number' => '0000019'], static fn (Party $p): ?string => $p->registrationNumber()?->value, '00000019'];
        yield 'registration country' => [['registration_country' => 'SK', 'registration_number' => '12345678'], static fn (Party $p): ?RegistrationNumber => $p->registrationNumber(), new RegistrationNumber('SK', '12345678')];
        yield 'VAT id' => [['vat_id' => 'CZ12345687'], static fn (Party $p): ?string => $p->vatId()?->toString(), 'CZ12345687'];
        yield 'e-mails' => [['email' => ['info@example.com', 'copy@example.com']], self::emails(...), [['info@example.com', EmailPurpose::General], ['copy@example.com', EmailPurpose::Copy]]];
        yield 'copy e-mails' => [['email' => ['info@example.com'], 'copy_email' => ['copy@example.com', 'info@example.com']], self::emails(...), [['info@example.com', EmailPurpose::General], ['copy@example.com', EmailPurpose::Copy]]];
        yield 'invoice e-mail' => [['invoice_email' => 'invoices@example.com'], self::emails(...), [['invoices@example.com', EmailPurpose::Invoices]]];
        yield 'invoice e-mail that is the general one' => [['email' => ['info@example.com'], 'invoice_email' => 'INFO@example.com'], self::emails(...), [['info@example.com', EmailPurpose::General]]];
        yield 'phone' => [['phone' => '+420 123 456 789'], static fn (Party $p): string => $p->phone(), '+420 123 456 789'];
        yield 'mobile' => [['mobile' => '+420 987 654 321'], static fn (Party $p): string => $p->mobile(), '+420 987 654 321'];
        yield 'website' => [['website' => 'https://www.example.com'], static fn (Party $p): string => $p->website(), 'https://www.example.com'];
        yield 'roles' => [['roles' => 'Supplier; partner,supplier'], static fn (Party $p): array => $p->roles(), [PartyRole::Supplier, PartyRole::Partner]];
        yield 'roles by their Czech names' => [['roles' => 'zákazník; DODAVATEL, Úřad'], static fn (Party $p): array => $p->roles(), [PartyRole::Customer, PartyRole::Supplier, PartyRole::Authority]];
        yield 'roles by their English names without diacritics' => [['roles' => 'Supplier , partner;authority'], static fn (Party $p): array => $p->roles(), [PartyRole::Supplier, PartyRole::Partner, PartyRole::Authority]];
        yield 'roles named without diacritics' => [['roles' => 'Zakaznik, urad'], static fn (Party $p): array => $p->roles(), [PartyRole::Customer, PartyRole::Authority]];
        yield 'customer by default' => [[], static fn (Party $p): array => $p->roles(), [PartyRole::Customer]];
        yield 'billing address' => [['billing_country' => 'SK'], static fn (Party $p, PartyDetails $d): ?Address => $d->billingAddress($p)?->address(), new Address('Example Street 1', 'Example City', '100 00', 'SK')];
        yield 'empty billing address in the registration country' => [['billing_street' => null, 'billing_city' => null, 'billing_postal_code' => null, 'registration_country' => 'SK', 'registration_number' => '12345678'], static fn (Party $p, PartyDetails $d): ?Address => $d->billingAddress($p)?->address(), new Address('', '', '', 'SK')];
        yield 'delivery address' => [['delivery_street' => 'Example Street 2', 'delivery_city' => 'Example Town', 'delivery_postal_code' => '200 00'], self::delivery(...), new Address('Example Street 2', 'Example Town', '200 00', 'CZ')];
        yield 'delivery address abroad' => [['delivery_street' => 'Example Street 2', 'delivery_city' => 'Example Town', 'delivery_postal_code' => '200 00', 'delivery_country' => 'DE'], self::delivery(...), new Address('Example Street 2', 'Example Town', '200 00', 'DE')];
        yield 'IBAN' => [['bank_account_iban' => 'CZ0303000000190000123457', 'bank_account_bic' => 'examczpp'], self::accounts(...), [['CZ0303000000190000123457', 'EXAMCZPP', true]]];
        yield 'domestic account' => [['bank_account_number' => '123456789', 'bank_code' => '0100'], self::accounts(...), [['CZ1801000000000123456789', null, true]]];
        yield 'IBAN wins over the domestic account' => [['bank_account_iban' => 'CZ0303000000190000123457', 'bank_account_number' => '123456789', 'bank_code' => '0100'], self::accounts(...), [['CZ0303000000190000123457', null, true]]];
        yield 'contact person' => [['contact_title' => 'Ing.', 'contact_first_name' => 'Jana', 'contact_last_name' => 'Example', 'contact_email' => 'jana@example.com', 'contact_phone' => '+420 111 222 333', 'contact_position' => 'Accountant'], self::contact(...), ['Ing. Jana Example', 'jana@example.com', '+420 111 222 333', 'Accountant', true]];
        yield 'contact person by full name' => [['contact_name' => 'Jana Example', 'contact_first_name' => 'Ignored'], self::contact(...), ['Jana Example', null, '', '', true]];
        yield 'due days' => [['due_days' => 30], static fn (Party $p): int => $p->invoicingDefaults()->dueDays, 30];
        yield 'currency' => [['currency' => 'EUR'], static fn (Party $p): Currency => $p->invoicingDefaults()->currency, Currency::EUR];
        yield 'payment method' => [['payment_method' => 'cash'], static fn (Party $p): PaymentMethod => $p->invoicingDefaults()->paymentMethod, PaymentMethod::Cash];
        yield 'private note' => [['private_note' => 'Pays late'], static fn (Party $p): string => $p->privateNote(), 'Pays late'];
    }

    /**
     * @param array<string, mixed>                 $values
     * @param callable(Party, PartyDetails): mixed $actual
     */
    #[DataProvider('fieldValues')]
    public function testEachFieldIsWrittenOnCreate(array $values, callable $actual, mixed $expected): void
    {
        $values = $this->values($values);
        self::assertSame([], $this->target->validate($values));

        $id = $this->create($values)->id;

        self::assertEquals($expected, $actual($this->find($id), $this->details()));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, string>}>
     */
    public static function invalidValues(): iterable
    {
        yield 'no name' => [['display_name' => null], ['display_name' => 'import.error.required']];
        yield 'padded but invalid registration number' => [['registration_number' => '1234567'], ['registration_number' => 'import.error.invalid_registration_number']];
        yield 'invalid registration number' => [['registration_number' => '12345678'], ['registration_number' => 'import.error.invalid_registration_number']];
        yield 'invalid VAT id' => [['vat_id' => 'CZ1'], ['vat_id' => 'import.error.invalid_vat_id']];
        yield 'company without identifiers' => [['registration_number' => null, 'kind' => 'company'], ['registration_number' => 'import.error.company_identifier']];
        yield 'company with an invalid VAT id only' => [['registration_number' => null, 'kind' => 'company', 'vat_id' => 'CZ1'], ['vat_id' => 'import.error.invalid_vat_id']];
        yield 'e-mail the address book refuses' => [['email' => ['user@localhost']], ['email' => 'import.error.invalid_email']];
        yield 'copy e-mail' => [['copy_email' => ['ok@example.com', 'user@localhost']], ['copy_email' => 'import.error.invalid_email']];
        yield 'invoice e-mail' => [['invoice_email' => 'user@localhost'], ['invoice_email' => 'import.error.invalid_email']];
        yield 'contact e-mail' => [['contact_name' => 'Jana Example', 'contact_email' => 'user@localhost'], ['contact_email' => 'import.error.invalid_email']];
        yield 'too long e-mail' => [['email' => [str_repeat('a', 250).'@example.com']], ['email' => 'import.error.invalid_email']];
        yield 'partial billing address' => [['billing_street' => null, 'billing_postal_code' => null], ['billing_street' => 'import.error.required', 'billing_postal_code' => 'import.error.required']];
        yield 'partial delivery address' => [['delivery_city' => 'Example Town'], ['delivery_street' => 'import.error.required', 'delivery_postal_code' => 'import.error.required']];
        yield 'invalid IBAN' => [['bank_account_iban' => 'CZ0003000000190000123457'], ['bank_account_iban' => 'import.error.invalid_iban']];
        yield 'invalid BIC' => [['bank_account_iban' => 'CZ0303000000190000123457', 'bank_account_bic' => 'EXAM'], ['bank_account_bic' => 'import.error.invalid_bic']];
        yield 'account number without a bank code' => [['bank_account_number' => '123456789'], ['bank_code' => 'import.error.invalid_bank_account']];
        yield 'bank code without an account number' => [['bank_code' => '0100'], ['bank_account_number' => 'import.error.invalid_bank_account']];
        yield 'invalid domestic account' => [['bank_account_number' => '12A', 'bank_code' => '0100'], ['bank_account_number' => 'import.error.invalid_bank_account']];
        yield 'unknown role' => [['roles' => 'customer, boss'], ['roles' => 'import.error.unknown_value']];
        yield 'unknown Czech role' => [['roles' => 'zákazník, šéf'], ['roles' => 'import.error.unknown_value']];
        yield 'due days out of range' => [['due_days' => 366], ['due_days' => 'import.error.out_of_range']];
        yield 'negative due days' => [['due_days' => -1], ['due_days' => 'import.error.out_of_range']];
        yield 'too long name' => [['display_name' => str_repeat('a', 256)], ['display_name' => 'import.error.too_long']];
        yield 'too long phone' => [['phone' => str_repeat('1', 65)], ['phone' => 'import.error.too_long']];
        yield 'too long contact name' => [['contact_name' => str_repeat('a', 256)], ['contact_last_name' => 'import.error.too_long']];
        yield 'too long position' => [['contact_name' => 'Jana Example', 'contact_position' => str_repeat('a', 101)], ['contact_position' => 'import.error.too_long']];
    }

    /**
     * @param array<string, mixed>  $values
     * @param array<string, string> $errors
     */
    #[DataProvider('invalidValues')]
    public function testInvalidValuesAreRowErrors(array $values, array $errors): void
    {
        $found = [];
        foreach ($this->target->validate($this->values($values)) as $error) {
            $found[$error->field] = $error->messageKey;
        }

        self::assertSame($errors, $found);
    }

    public function testErrorsCarryTheValueForTheMessage(): void
    {
        self::assertEquals(
            [new RowError('registration_number', 'import.error.invalid_registration_number', ['value' => '1234567'])],
            $this->target->validate($this->values(['registration_number' => '1234567'])),
        );
        self::assertEquals(
            [new RowError('phone', 'import.error.too_long', ['value' => str_repeat('1', 65), 'max' => 64])],
            $this->target->validate($this->values(['phone' => str_repeat('1', 65)])),
        );
        self::assertEquals(
            [new RowError('due_days', 'import.error.out_of_range', ['value' => 400, 'min' => 0, 'max' => 365])],
            $this->target->validate($this->values(['due_days' => 400])),
        );
        self::assertEquals(
            [new RowError('bank_code', 'import.error.invalid_bank_account', ['value' => '123456789/'])],
            $this->target->validate($this->values(['bank_account_number' => '123456789'])),
        );
    }

    public function testMatchesByEachKeyWithinTheTenantAndReportsArchivedRecords(): void
    {
        $id = $this->create($this->values(['vat_id' => 'CZ12345687', 'email' => ['Info@example.com']]))->id;

        foreach ([['id', ['id' => $id]], ['registration_number', ['registration_number' => '12345687']], ['vat_id', ['vat_id' => 'CZ12345687']], ['email', ['email' => ['info@EXAMPLE.com']]]] as [$key, $values]) {
            self::assertEquals([new RecordMatch($id, false)], $this->target->match($key, $this->values($values)), $key);
        }
        self::assertSame([], $this->target->match('registration_number', $this->values(['registration_number' => '12345695'])));
        self::assertSame([], $this->target->match('registration_number', $this->values(['registration_country' => 'SK'])));
        self::assertSame([], $this->target->match('id', $this->values(['id' => null])));
        self::assertSame([], $this->target->match('email', $this->values()));
        $this->details()->generalEmail($this->find($id))?->change('Changed@example.com', EmailPurpose::General);
        $this->em->flush();
        self::assertEquals([new RecordMatch($id, false)], $this->target->match('email', $this->values(['email' => ['changed@example.COM']])));
        self::assertSame([], $this->target->match('email', $this->values(['email' => ['info@example.com']])));

        $this->target->archive($id);
        self::assertEquals([new RecordMatch($id, true)], $this->target->match('id', $this->values(['id' => $id])));

        $this->expectException(\InvalidArgumentException::class);
        $this->target->match('phone', $this->values());
    }

    public function testMatchingByEmailLeavesOutContactPersonsWithTheSameAddress(): void
    {
        $values = $this->values(['email' => ['office@example.com'], 'contact_name' => 'Jana Example', 'contact_email' => 'office@example.com']);
        $id = $this->create($values)->id;
        self::assertSame(2, $this->rowCount("SELECT COUNT(*) FROM party_email WHERE address_key = 'office@example.com'"));

        $exported = $this->normalise([...$this->target->export([$id])][0]);
        self::assertEquals([new RecordMatch($id, false)], $this->target->match('email', $exported));
        self::assertEquals([new RecordMatch($id, false)], $this->target->match('email', $values));
    }

    public function testTheTargetOfOneTenantNeverMatchesTheRecordsOfAnother(): void
    {
        $id = $this->create($this->values(['vat_id' => 'CZ12345687', 'email' => ['info@example.com']]))->id;

        $this->tenants->switchTo(TenantId::generate());

        foreach (['id' => ['id' => $id], 'registration_number' => [], 'vat_id' => ['vat_id' => 'CZ12345687'], 'email' => ['email' => ['info@example.com']]] as $key => $values) {
            self::assertSame([], $this->target->match($key, $this->values($values)), $key);
        }
        self::assertSame([], [...$this->target->activeIds()]);
        self::assertSame([], [...$this->target->export([$id])]);
        $this->expectException(\DomainException::class);
        $this->target->update($id, $this->values(), BlankCells::Keep);
    }

    public function testUpdateMergesAndAddsTheChildrenThatAreMissing(): void
    {
        $id = $this->create($this->values(['email' => ['info@example.com', 'copy@example.com'], 'bank_account_iban' => 'CZ0303000000190000123457', 'delivery_street' => 'Example Street 2', 'delivery_city' => 'Example Town', 'delivery_postal_code' => '200 00', 'contact_name' => 'Jana Example']))->id;

        $result = $this->update($id, [
            'display_name' => 'Example Company Renamed',
            'email' => ['new@example.com', 'second@example.com'],
            'bank_account_number' => '123456789',
            'bank_code' => '0100',
            'delivery_street' => 'Example Street 3',
            'delivery_city' => 'Other Town',
            'delivery_postal_code' => '300 00',
            'contact_name' => 'Petr Example',
            'billing_city' => 'Moved City',
        ]);

        $party = $this->find($id);
        self::assertSame('Example Company Renamed', $party->displayName());
        self::assertSame('Moved City', $this->details()->billingAddress($party)?->address()->city);
        self::assertSame([['new@example.com', EmailPurpose::General], ['copy@example.com', EmailPurpose::Copy], ['second@example.com', EmailPurpose::Copy]], self::emails($party, $this->details()));
        // The first account stays primary; a party has one delivery address from an import.
        self::assertSame([['CZ0303000000190000123457', null, true], ['CZ1801000000000123456789', null, false]], self::accounts($party, $this->details()));
        self::assertSame(['Example Street 2'], array_map(static fn (PartyAddress $a): string => $a->address()->street, array_values(array_filter($this->details()->addresses($party), static fn (PartyAddress $a): bool => AddressType::Delivery === $a->type()))));
        self::assertSame(['Jana Example', 'Petr Example'], array_map(static fn (ContactLink $l): string => $l->person()->displayName(), $this->details()->contactLinks($party)));
        self::assertSame([true, false], array_map(static fn (ContactLink $l): bool => $l->isPrimary(), $this->details()->contactLinks($party)));

        $data = $result->before->data;
        self::assertIsArray($data['added']);
        self::assertSame(['emails', 'bankAccounts', 'contactLinks', 'persons'], array_keys($data['added']));
        self::assertIsArray($data['added']['emails']);
        self::assertCount(1, $data['added']['emails']);
        self::assertIsArray($data['party']);
        self::assertSame('Example Company', $data['party']['displayName']);
        self::assertIsArray($data['generalEmail']);
        self::assertSame('info@example.com', $data['generalEmail']['address']);
    }

    public function testBlankCellsKeepOrClearTheOptionalFields(): void
    {
        $full = ['phone' => '+420 123 456 789', 'mobile' => '+420 987 654 321', 'website' => 'https://www.example.com', 'vat_id' => 'CZ12345687', 'private_note' => 'Note', 'roles' => 'supplier', 'due_days' => 30, 'currency' => 'EUR', 'payment_method' => 'cash', 'email' => ['info@example.com'], 'billing_country' => 'SK'];
        $id = $this->create($this->values($full))->id;
        $blank = array_map(static fn (): null => null, $full) + ['display_name' => null, 'registration_number' => null, 'billing_street' => null, 'billing_city' => null, 'billing_postal_code' => null];

        $this->target->update($id, $blank, BlankCells::Keep);
        $party = $this->find($id);
        self::assertSame(['+420 123 456 789', '+420 987 654 321', 'https://www.example.com', 'CZ12345687', 'Note', [PartyRole::Supplier], 30, Currency::EUR, PaymentMethod::Cash], $this->scalars($party));
        self::assertSame('Example Company', $party->displayName());
        self::assertEquals(new Address('Example Street 1', 'Example City', '100 00', 'SK'), $this->details()->billingAddress($party)?->address());

        $this->target->update($id, $blank, BlankCells::Clear);
        $party = $this->find($id);
        self::assertSame(['', '', '', 'CZ12345687', '', [], 14, Currency::CZK, PaymentMethod::BankTransfer], $this->scalars($party), 'a company keeps an identifier');
        self::assertSame('12345687', $party->registrationNumber()?->value);
        self::assertSame('Example Company', $party->displayName(), 'the name is required');
        self::assertEquals(new Address('', '', '', 'CZ'), $this->details()->billingAddress($party)?->address());
        self::assertSame([['info@example.com', EmailPurpose::General]], self::emails($party, $this->details()), 'children are never removed');

        // A field the source has no column for is never touched, whatever the mode.
        $this->target->update($id, ['display_name' => 'Example Company', 'phone' => '+420 555 555 555'], BlankCells::Clear);
        self::assertSame('12345687', $this->find($id)->registrationNumber()?->value);
    }

    public function testClearingTheIdentifiersOfAPersonRemovesThem(): void
    {
        $id = $this->create($this->values(['kind' => 'person', 'registration_number' => '12345687', 'vat_id' => 'CZ8501011234']))->id;

        $this->target->update($id, ['display_name' => 'Example Person', 'registration_number' => null, 'vat_id' => null], BlankCells::Clear);

        $party = $this->find($id);
        self::assertNull($party->registrationNumber());
        self::assertNull($party->vatId());
        self::assertSame(PartyKind::Person, $party->kind());
    }

    public function testTheContactPersonIsLinkedOnceByNameOrEmail(): void
    {
        $contact = ['contact_title' => 'Ing.', 'contact_first_name' => 'Jana', 'contact_last_name' => 'Example', 'contact_email' => 'jana@example.com'];
        $id = $this->create($this->values($contact))->id;

        $this->update($id, $contact);
        $this->update($id, ['contact_name' => 'Jana Example-Married', 'contact_email' => 'JANA@example.com']);

        $links = $this->details()->contactLinks($this->find($id));
        self::assertCount(1, $links);
        self::assertSame('Ing. Jana Example', $links[0]->person()->displayName());
        self::assertSame(1, $this->rowCount("SELECT COUNT(*) FROM party WHERE kind = 'person'"));
    }

    public function testAPersonGetsNoContactPerson(): void
    {
        $id = $this->create($this->values(['kind' => 'person', 'registration_number' => null, 'contact_name' => 'Someone Else']))->id;

        self::assertSame([], $this->details()->contactLinks($this->find($id)));
        self::assertSame(1, $this->rowCount('SELECT COUNT(*) FROM party'));
    }

    public function testArchiveRestoreAndActiveIds(): void
    {
        $company = $this->create($this->values(['contact_name' => 'Jana Example']))->id;
        $other = $this->create($this->values(['registration_number' => '12345695', 'display_name' => 'Example Supplies']))->id;

        // The contact person stands with its company.
        self::assertSame([$company, $other], [...$this->target->activeIds()]);

        self::assertEquals(new RecordSnapshot(['archived' => false]), $this->target->archive($other));
        self::assertTrue($this->find($other)->isArchived());
        self::assertSame([$company], [...$this->target->activeIds()]);

        $this->target->restoreArchived($other);
        self::assertFalse($this->find($other)->isArchived());
    }

    public function testRevertOfACreatedPartyArchivesItAndTheContactPersonItBrought(): void
    {
        $plain = $this->create($this->values(['registration_number' => '12345695']));
        self::assertSame(['created' => true, 'children' => ['addresses' => [$this->details()->billingAddress($this->find($plain->id))?->id()]]], $plain->before->data);
        $result = $this->create($this->values(['contact_name' => 'Jana Example']));
        $link = $this->details()->contactLinks($this->find($result->id))[0];
        $person = $link->person()->id();
        self::assertSame(['persons' => [$person]], $result->before->data['added'] ?? null);
        self::assertIsArray($result->before->data['children'] ?? null);
        self::assertSame([$link->id()], $result->before->data['children']['contactLinks'] ?? null);

        $this->target->revert($plain->id, $plain->before);
        $this->target->revert($result->id, $result->before);

        self::assertTrue($this->find($plain->id)->isArchived());
        self::assertTrue($this->find($result->id)->isArchived());
        self::assertTrue($this->find((int) $person)->isArchived());
        self::assertSame(3, $this->rowCount('SELECT COUNT(*) FROM party'), 'archived, never deleted');
    }

    public function testRevertOfAnUpdatePutsTheFieldsBackAndRemovesExactlyWhatItAdded(): void
    {
        $id = $this->create($this->values(['email' => ['info@example.com', 'copy@example.com'], 'phone' => '+420 123 456 789', 'website' => 'https://old.example.com', 'roles' => 'supplier', 'due_days' => 30, 'currency' => 'EUR', 'bank_account_iban' => 'CZ0303000000190000123457']))->id;
        $party = $this->find($id);
        $party->changeInvoicingDefaults(new \App\Party\Domain\InvoicingDefaults(30, Currency::EUR, \App\Party\Domain\DocumentLanguage::English, PaymentMethod::Card, true));
        $this->em->flush();
        $before = $this->state($id);

        $result = $this->update($id, [
            'display_name' => 'Renamed',
            'vat_id' => 'CZ12345687',
            'email' => ['new@example.com', 'added@example.com'],
            'phone' => '+420 999 999 999',
            'mobile' => '+420 888 888 888',
            'website' => 'https://new.example.com',
            'roles' => 'partner',
            'private_note' => 'New note',
            'due_days' => 7,
            'currency' => 'USD',
            'payment_method' => 'cash',
            'billing_street' => 'Example Street 9',
            'billing_city' => 'Brno',
            'billing_postal_code' => '602 00',
            'billing_country' => 'SK',
            'bank_account_number' => '123456789',
            'bank_code' => '0100',
            'delivery_street' => 'Example Street 2',
            'delivery_city' => 'Example Town',
            'delivery_postal_code' => '200 00',
            'contact_name' => 'Jana Example',
        ]);
        self::assertNotEquals($before, $this->state($id));

        $this->target->revert($id, $result->before);

        self::assertEquals($before, $this->state($id));
        self::assertSame(1, $this->rowCount("SELECT COUNT(*) FROM party WHERE kind = 'person' AND archived_at IS NOT NULL"));
    }

    public function testRevertOfAnUpdateRemovesAGeneralEmailItAdded(): void
    {
        $id = $this->create($this->values())->id;

        $result = $this->update($id, ['email' => ['first@example.com']]);
        self::assertSame([['first@example.com', EmailPurpose::General]], self::emails($this->find($id), $this->details()));

        $this->target->revert($id, $result->before);
        self::assertSame([], self::emails($this->find($id), $this->details()));
    }

    public function testRecheckIsDispatchedForCreatedAndReidentifiedPartiesOnly(): void
    {
        $withVat = $this->create($this->values(['vat_id' => 'CZ12345687']))->id;
        $withoutVat = $this->create($this->values(['registration_number' => '12345695']))->id;
        self::assertEquals([new RecheckRegistry($withVat, self::CORRELATION)], $this->rechecks());

        $this->update($withVat, ['phone' => '+420 123 456 789', 'vat_id' => 'CZ12345687']);
        self::assertEquals([new RecheckRegistry($withVat, self::CORRELATION)], $this->rechecks(), 'the same identifiers need no recheck');

        $this->update($withoutVat, ['registration_number' => '00000019']);
        self::assertEquals([new RecheckRegistry($withVat, self::CORRELATION)], $this->rechecks(), 'nothing to check without a VAT id');

        $this->update($withoutVat, ['vat_id' => 'CZ12345695']);
        $this->update($withVat, ['registration_number' => '00000019']);
        self::assertEquals([new RecheckRegistry($withVat, self::CORRELATION), new RecheckRegistry($withoutVat, self::CORRELATION), new RecheckRegistry($withVat, self::CORRELATION)], $this->rechecks());
    }

    public function testChangedSinceCoversThePartyItsChildrenAndWhatTheImportAdded(): void
    {
        $id = $this->create($this->values(['email' => ['info@example.com', 'copy@example.com'], 'bank_account_iban' => 'CZ0303000000190000123457']))->id;
        $result = $this->update($id, ['contact_name' => 'Jana Example', 'email' => ['info@example.com', 'added@example.com']]);
        $since = new \DateTimeImmutable('2026-10-01 10:00:00');
        $other = $this->create($this->values(['registration_number' => '12345695', 'display_name' => 'Other']))->id;
        self::assertFalse($this->target->changedSince($id, $result->before, $since, [self::CORRELATION]));

        self::mockTime('2026-10-01 11:00:00');
        $this->find($other)->changePhone('+420 000 000 000');
        $this->em->flush();
        self::assertFalse($this->target->changedSince($id, $result->before, $since, [self::CORRELATION]), 'another party');

        $person = $this->details()->contactLinks($this->find($id))[0]->person();
        $person->changePhone('+420 111 111 111');
        $this->em->flush();
        self::assertTrue($this->target->changedSince($id, $result->before, $since, [self::CORRELATION]), 'the contact person the import created');
        self::assertFalse($this->target->changedSince($id, RecordSnapshot::none(), $since, [self::CORRELATION]), 'a person the record does not name');
        self::assertFalse($this->target->changedSince($id, $result->before, new \DateTimeImmutable('2026-10-01 12:00:00'), [self::CORRELATION]), 'before since');
    }

    /**
     * @return iterable<string, array{callable(self, int): void}>
     */
    public static function changesAfterTheImport(): iterable
    {
        yield 'the party' => [static function (self $test, int $id): void {
            $test->find($id)->changePhone('+420 000 000 000');
        }];
        yield 'an e-mail' => [static function (self $test, int $id): void {
            $test->details()->emails($test->find($id))[1]->change('edited@example.com', EmailPurpose::Copy);
        }];
        yield 'an address' => [static function (self $test, int $id): void {
            $test->details()->billingAddress($test->find($id))?->relabel('Head office');
        }];
        yield 'a bank account' => [static function (self $test, int $id): void {
            $test->details()->bankAccounts($test->find($id))[0]->demote();
        }];
        yield 'a contact link' => [static function (self $test, int $id): void {
            $test->details()->contactLinks($test->find($id))[0]->changePosition('Director');
        }];
        yield 'a removed e-mail' => [static function (self $test, int $id): void {
            $test->em->remove($test->details()->emails($test->find($id))[1]);
        }];
        yield 'a removed link' => [static function (self $test, int $id): void {
            $test->em->remove($test->details()->contactLinks($test->find($id))[0]);
        }];
        yield 'a new e-mail' => [static function (self $test, int $id): void {
            $test->em->persist(new PartyEmail($test->find($id), 'hand@example.com', EmailPurpose::Copy));
        }];
    }

    /**
     * @param callable(self, int): void $change
     */
    #[DataProvider('changesAfterTheImport')]
    public function testAChangeToThePartyOrAnyChildAfterTheImportCounts(callable $change): void
    {
        $result = $this->create($this->values(['email' => ['info@example.com', 'copy@example.com'], 'bank_account_iban' => 'CZ0303000000190000123457', 'contact_name' => 'Jana Example']));
        $since = new \DateTimeImmutable('2026-10-01 10:00:00');
        self::assertFalse($this->target->changedSince($result->id, $result->before, $since, [self::CORRELATION]));

        self::mockTime('2026-10-01 11:00:00');
        $change($this, $result->id);
        $this->em->flush();

        self::assertTrue($this->target->changedSince($result->id, $result->before, $since, [self::CORRELATION]));
    }

    public function testTheUndoItselfIsNoChangeAfterTheImport(): void
    {
        $result = $this->create($this->values(['email' => ['info@example.com', 'copy@example.com']]));
        $since = new \DateTimeImmutable('2026-10-01 10:00:00');

        self::mockTime('2026-10-01 11:00:00');
        self::getContainer()->get(AuditCorrelation::class)->within('import-undo:1', function () use ($result): void {
            $this->em->remove($this->details()->emails($this->find($result->id))[1]);
            $this->em->flush();
        });

        self::assertFalse($this->target->changedSince($result->id, $result->before, $since, [self::CORRELATION, 'import-undo:1']));
        self::assertTrue($this->target->changedSince($result->id, $result->before, $since, [self::CORRELATION]));
    }

    public function testRemovingAChildThePartyHadBeforeAnUpdateCounts(): void
    {
        $id = $this->create($this->values(['email' => ['info@example.com', 'old-copy@example.com']]))->id;
        self::mockTime('2026-10-01 11:00:00');
        $since = new \DateTimeImmutable('2026-10-01 11:00:00');
        $result = self::getContainer()->get(AuditCorrelation::class)->within('import:2', fn (): \App\Import\Application\Target\WriteResult => $this->target->update($id, ['phone' => '+420 123 456 789'], BlankCells::Keep));

        self::mockTime('2026-10-01 12:00:00');
        $this->em->remove($this->details()->emails($this->find($id))[1]);
        $this->em->flush();

        self::assertTrue($this->target->changedSince($id, $result->before, $since, ['import:2']));
    }

    public function testARemovalOfAnotherPartysChildDoesNotCount(): void
    {
        $result = $this->create($this->values());
        $other = $this->create($this->values(['registration_number' => '12345695', 'display_name' => 'Other', 'email' => ['a@example.com', 'b@example.com']]))->id;

        self::mockTime('2026-10-01 11:00:00');
        $this->em->remove($this->details()->emails($this->find($other))[1]);
        $this->em->flush();

        self::assertFalse($this->target->changedSince($result->id, $result->before, new \DateTimeImmutable('2026-10-01 10:00:00'), [self::CORRELATION]));
    }

    public function testExportedContactsImportAgainWithoutAnyChange(): void
    {
        $id = $this->create($this->values([
            'vat_id' => 'CZ12345687',
            'email' => ['info@example.com', 'copy@example.com'],
            'invoice_email' => 'invoices@example.com',
            'phone' => '+420 123 456 789',
            'mobile' => '+420 987 654 321',
            'website' => 'https://www.example.com',
            'roles' => 'customer, supplier',
            'delivery_street' => 'Example Street 2',
            'delivery_city' => 'Example Town',
            'delivery_postal_code' => '200 00',
            'bank_account_iban' => 'CZ0303000000190000123457',
            'bank_account_bic' => 'EXAMCZPP',
            'contact_name' => 'Jana Example',
            'contact_email' => 'jana@example.com',
            'contact_phone' => '+420 111 222 333',
            'contact_position' => 'Accountant',
            'due_days' => 30,
            'currency' => 'EUR',
            'payment_method' => 'card',
            'private_note' => 'Pays late',
        ]))->id;
        $this->em->clear();

        $rows = [...$this->target->export([$id])];
        self::assertCount(1, $rows);
        self::assertSame(array_keys($this->fields()), array_keys($rows[0]), 'the field keys, so the file maps by name');
        self::assertSame('info@example.com, copy@example.com', $rows[0]['email']);
        self::assertSame('customer, supplier', $rows[0]['roles']);
        self::assertSame('Jana Example', $rows[0]['contact_name']);

        $values = $this->normalise($rows[0]);
        self::assertSame([], $this->target->validate($values));
        self::assertEquals([new RecordMatch($id, false)], $this->target->match('id', $values));
        self::getContainer()->get(AuditCorrelation::class)->within('import:2', fn (): \App\Import\Application\Target\WriteResult => $this->target->update($id, $values, BlankCells::Keep));
        self::getContainer()->get(AuditCorrelation::class)->within('import:3', fn (): \App\Import\Application\Target\WriteResult => $this->target->update($id, $values, BlankCells::Clear));

        self::assertSame(0, (int) $this->em->createQuery('SELECT COUNT(e.id) FROM '.AuditEntry::class." e WHERE e.correlationId IN ('import:2', 'import:3')")->getSingleScalarResult());
    }

    public function testCopiesOfAPartyWithoutAGeneralEmailStayCopiesThroughExportAndImport(): void
    {
        $id = $this->create($this->values(['copy_email' => ['copy@example.com', 'other@example.com']]))->id;
        $this->em->clear();

        $row = [...$this->target->export([$id])][0];
        self::assertSame(['', 'copy@example.com, other@example.com'], [$row['email'], $row['copy_email']]);
        self::getContainer()->get(AuditCorrelation::class)->within('import:2', fn (): \App\Import\Application\Target\WriteResult => $this->target->update($id, $this->normalise($row), BlankCells::Keep));

        self::assertSame(0, $this->rowCount("SELECT COUNT(*) FROM audit_entry WHERE correlation_id = 'import:2'"));
        self::assertSame([['copy@example.com', EmailPurpose::Copy], ['other@example.com', EmailPurpose::Copy]], self::emails($this->find($id), $this->details()));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function create(array $values): \App\Import\Application\Target\WriteResult
    {
        return self::getContainer()->get(AuditCorrelation::class)->within(self::CORRELATION, fn (): \App\Import\Application\Target\WriteResult => $this->target->create($values));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function update(int $id, array $values): \App\Import\Application\Target\WriteResult
    {
        return self::getContainer()->get(AuditCorrelation::class)->within(self::CORRELATION, fn (): \App\Import\Application\Target\WriteResult => $this->target->update($id, $values, BlankCells::Keep));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function values(array $overrides = []): array
    {
        return array_replace([
            'display_name' => 'Example Company',
            'registration_number' => '12345687',
            'billing_street' => 'Example Street 1',
            'billing_city' => 'Example City',
            'billing_postal_code' => '100 00',
        ], $overrides);
    }

    /**
     * @param array<string, string> $row
     *
     * @return array<string, mixed>
     */
    private function normalise(array $row): array
    {
        $normaliser = new ValueNormaliser();
        $fields = $this->fields();
        $values = [];
        foreach ($row as $key => $text) {
            $value = $normaliser->normalise($fields[$key], $text, new NormalisationOptions());
            self::assertNotInstanceOf(RowError::class, $value, $key);
            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * @return array<string, FieldDefinition>
     */
    private function fields(): array
    {
        $fields = [];
        foreach ($this->target->fields() as $field) {
            $fields[$field->key] = $field;
        }

        return $fields;
    }

    /**
     * @return list<mixed>
     */
    private function scalars(Party $party): array
    {
        $defaults = $party->invoicingDefaults();

        return [$party->phone(), $party->mobile(), $party->website(), $party->vatId()?->toString(), $party->privateNote(), $party->roles(), $defaults->dueDays, $defaults->currency, $defaults->paymentMethod];
    }

    /**
     * Everything an undo must put back, as plain values.
     *
     * @return array<string, mixed>
     */
    private function state(int $id): array
    {
        $this->em->clear();
        $party = $this->find($id);
        $details = $this->details();
        $defaults = $party->invoicingDefaults();

        return [
            'party' => [$party->displayName(), $party->registrationNumber()?->value, $party->vatId()?->toString(), $party->phone(), $party->mobile(), $party->website(), $party->roles(), $party->privateNote(), $party->isArchived()],
            'defaults' => [$defaults->dueDays, $defaults->currency, $defaults->language, $defaults->paymentMethod, $defaults->reverseCharge],
            'addresses' => array_map(static fn (PartyAddress $a): array => [$a->id(), $a->type(), $a->address()], $details->addresses($party)),
            'emails' => array_map(static fn (PartyEmail $e): array => [$e->id(), $e->address(), $e->purpose()], $details->emails($party)),
            'accounts' => array_map(static fn (PartyBankAccount $b): array => [$b->id(), $b->iban()->toString(), $b->isPrimary()], $details->bankAccounts($party)),
            'links' => array_map(static fn (ContactLink $l): ?int => $l->id(), $details->contactLinks($party)),
        ];
    }

    /**
     * @return list<object>
     */
    private function rechecks(): array
    {
        $transport = self::getContainer()->get('messenger.transport.registry');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_map(static fn (\Symfony\Component\Messenger\Envelope $envelope): object => $envelope->getMessage(), [...$transport->getSent()]));
    }

    private function rowCount(string $sql): int
    {
        $count = $this->em->getConnection()->fetchOne($sql);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function find(int $id): Party
    {
        return self::getContainer()->get(PartyQuery::class)->find($id) ?? throw new \LogicException('No such party.');
    }

    private function details(): PartyDetails
    {
        return self::getContainer()->get(PartyDetails::class);
    }

    /**
     * @return list<array{string, EmailPurpose}>
     */
    private static function emails(Party $party, PartyDetails $details): array
    {
        return array_map(static fn (PartyEmail $email): array => [$email->address(), $email->purpose()], $details->emails($party));
    }

    private static function delivery(Party $party, PartyDetails $details): ?Address
    {
        foreach ($details->addresses($party) as $address) {
            if (AddressType::Delivery === $address->type()) {
                return $address->address();
            }
        }

        return null;
    }

    /**
     * @return list<array{string, string|null, bool}>
     */
    private static function accounts(Party $party, PartyDetails $details): array
    {
        return array_map(static fn (PartyBankAccount $account): array => [$account->iban()->toString(), $account->bic(), $account->isPrimary()], $details->bankAccounts($party));
    }

    /**
     * @return array{string, string|null, string, string, bool}|null
     */
    private static function contact(Party $party, PartyDetails $details): ?array
    {
        $link = $details->contactLinks($party)[0] ?? null;
        if (!$link instanceof ContactLink) {
            return null;
        }
        $person = $link->person();

        return [$person->displayName(), $details->generalEmail($person)?->address(), $person->phone(), $link->position(), $link->isPrimary()];
    }
}
