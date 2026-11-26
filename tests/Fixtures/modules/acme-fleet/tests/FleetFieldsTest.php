<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Acme\Fleet\Tests;

use Acme\Fleet\Domain\ContactExtra;
use Foresi\Audit\Domain\AuditAction;
use Foresi\Audit\Domain\AuditEntry;
use Foresi\Identity\Domain\Role;
use Foresi\Party\Contract\Field\ContactExtension;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\WorkingFilter;
use Foresi\Shared\Contract\Field\FieldKind;
use Foresi\Shared\Contract\Field\Fields;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The fleet's custom fields of a contact (ContactExtra): declared by a module of another
 * vendor, read and written through Fields, shown, filtered, sorted and searched in the
 * contact list, and audited under the contact.
 */
final class FleetFieldsTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
    }

    public function testTheModuleDeclaresItsFieldsOnTheContact(): void
    {
        $definitions = [];
        foreach ($this->fields()->definitions(ContactExtension::RECORD) as $definition) {
            $definitions[$definition->key] = $definition;
        }

        self::assertSame(['acme_fleet_industry', 'acme_fleet_contract_until', 'acme_fleet_account_code', 'acme_fleet_services'], array_keys($definitions));
        self::assertSame(FieldKind::Select, $definitions['acme_fleet_industry']->kind);
        self::assertSame(['logistics', 'construction', 'retail'], array_keys($definitions['acme_fleet_industry']->options));
        self::assertSame('acme-fleet', $definitions['acme_fleet_industry']->module);
        self::assertTrue($definitions['acme_fleet_contract_until']->sortable);
        self::assertTrue($definitions['acme_fleet_account_code']->searchable);
        self::assertSame(FieldKind::MultiSelect, $definitions['acme_fleet_services']->kind);
    }

    public function testAValueIsWrittenReadAndAuditedUnderTheContact(): void
    {
        $this->signIn(Role::Member);
        $contact = $this->contact('Example Haulage');

        $this->fields()->write(ContactExtension::RECORD, $contact, ['acme_fleet_industry' => 'logistics', 'acme_fleet_contract_until' => new \DateTimeImmutable('2027-03-31'), 'acme_fleet_services' => ['leasing', 'insurance']]);
        $this->browser->em()->flush();
        $this->browser->em()->clear();

        $row = $this->browser->em()->getConnection()->fetchAssociative('SELECT industry, contract_until, services FROM acme_fleet_contact_extra WHERE id = ?', [$contact]);
        self::assertSame(['industry' => 'logistics', 'contract_until' => '2027-03-31', 'services' => '["leasing","insurance"]'], $row);
        self::assertSame('logistics', $this->fields()->value(ContactExtension::RECORD, $contact, 'acme_fleet_industry'));
        $entries = $this->browser->em()->getRepository(AuditEntry::class)->findBy(['rootClass' => Party::class, 'rootId' => $contact, 'entityClass' => ContactExtra::class]);
        self::assertCount(1, $entries);
        self::assertSame(AuditAction::Created, $entries[0]->action());
        self::assertSame([null, 'logistics'], $entries[0]->changes()['industry']);
    }

    public function testClearingTheLastValueRemovesTheRow(): void
    {
        $this->signIn(Role::Member);
        $contact = $this->contact('Example Haulage');
        $this->fields()->write(ContactExtension::RECORD, $contact, ['acme_fleet_industry' => 'retail']);
        $this->browser->em()->flush();

        $this->fields()->write(ContactExtension::RECORD, $contact, ['acme_fleet_industry' => null]);
        $this->browser->em()->flush();

        self::assertSame(0, (int) $this->browser->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM acme_fleet_contact_extra'));
        self::assertNull($this->fields()->value(ContactExtension::RECORD, $contact, 'acme_fleet_industry'));
    }

    public function testAValueItsKindDoesNotTakeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->fields()->write(ContactExtension::RECORD, '0192f1a4-0000-7000-8000-000000000001', ['acme_fleet_industry' => 'farming']);
    }

    public function testTheContactListShowsFiltersAndSortsByTheFields(): void
    {
        $this->signIn(Role::Member);
        $haulage = $this->contact('Example Haulage');
        $builders = $this->contact('Example Builders');
        $this->contact('Example Shop');
        $this->fields()->write(ContactExtension::RECORD, $haulage, ['acme_fleet_industry' => 'logistics', 'acme_fleet_contract_until' => new \DateTimeImmutable('2027-03-31')]);
        $this->fields()->write(ContactExtension::RECORD, $builders, ['acme_fleet_industry' => 'construction', 'acme_fleet_contract_until' => new \DateTimeImmutable('2026-12-31')]);
        $this->browser->em()->flush();

        $filter = WorkingFilter::encode(ViewFilter::all(new Condition('acme_fleet_industry', 'is_any_of', ['logistics', 'construction'])));
        $crawler = $this->client->request('GET', '/contacts?cols=name,acme_fleet_industry&sort=acme_fleet_contract_until&f='.$filter);

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('main')->text();
        self::assertStringContainsString('Industry', $text);
        self::assertStringContainsString('Logistics', $text);
        self::assertStringNotContainsString('Example Shop', $text);
        self::assertLessThan(strpos($text, 'Example Haulage'), strpos($text, 'Example Builders'), 'The earlier contract end comes first.');
    }

    public function testASetAndADateFilterTheContacts(): void
    {
        $this->signIn(Role::Member);
        $haulage = $this->contact('Example Haulage');
        $builders = $this->contact('Example Builders');
        $this->contact('Example Shop');
        $this->fields()->write(ContactExtension::RECORD, $haulage, ['acme_fleet_services' => ['leasing', 'servicing'], 'acme_fleet_contract_until' => new \DateTimeImmutable('2027-03-31')]);
        $this->fields()->write(ContactExtension::RECORD, $builders, ['acme_fleet_services' => ['insurance'], 'acme_fleet_contract_until' => new \DateTimeImmutable('2026-12-31')]);
        $this->browser->em()->flush();

        $leasing = $this->client->request('GET', '/contacts?f='.WorkingFilter::encode(ViewFilter::all(new Condition('acme_fleet_services', 'has_any_of', ['leasing']))))->filter('main')->text();
        $later = $this->client->request('GET', '/contacts?f='.WorkingFilter::encode(ViewFilter::all(new Condition('acme_fleet_contract_until', 'after', '2027-01-01'))))->filter('main')->text();
        $none = $this->client->request('GET', '/contacts?f='.WorkingFilter::encode(ViewFilter::all(new Condition('acme_fleet_services', 'is_empty'))))->filter('main')->text();

        self::assertStringContainsString('Example Haulage', $leasing);
        self::assertStringNotContainsString('Example Builders', $leasing);
        self::assertStringContainsString('Example Haulage', $later);
        self::assertStringNotContainsString('Example Builders', $later);
        self::assertStringContainsString('Example Shop', $none);
        self::assertStringNotContainsString('Example Haulage', $none);
    }

    public function testTheContactSearchReadsASearchableField(): void
    {
        $this->signIn(Role::Member);
        $haulage = $this->contact('Example Haulage');
        $this->contact('Example Shop');
        $this->fields()->write(ContactExtension::RECORD, $haulage, ['acme_fleet_account_code' => 'FLEET-0042']);
        $this->browser->em()->flush();

        $crawler = $this->client->request('GET', '/contacts?q=fleet-0042');

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('main')->text();
        self::assertStringContainsString('Example Haulage', $text);
        self::assertStringNotContainsString('Example Shop', $text);
    }

    private function fields(): Fields
    {
        $fields = self::getContainer()->get(Fields::class);
        \assert($fields instanceof Fields);

        return $fields;
    }

    private function contact(string $name): string
    {
        $contact = new Party(PartyKind::Person, $name, null, null, '');
        $this->browser->em()->persist($contact);
        $this->browser->em()->flush();

        return $contact->id();
    }

    private function signIn(Role $role): void
    {
        $this->browser->seed(role: $role);
        // A firm, so the instance is past its first-run setup.
        $em = $this->browser->em();
        $em->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $em->flush();
        $this->browser->signIn();
    }
}
