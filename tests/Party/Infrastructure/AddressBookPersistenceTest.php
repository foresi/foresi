<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Infrastructure;

use App\Audit\Domain\AuditEntry;
use App\Party\Application\CreateParty;
use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\ContactLink;
use App\Party\Domain\EmailPurpose;
use App\Party\Domain\Iban;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyBankAccount;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\RegistryCheck;
use App\Party\Domain\RegistryResult;
use App\Party\Domain\RegistrySource;
use App\Party\Domain\VatId;
use App\Party\Infrastructure\Doctrine\DoctrineContactLinks;
use App\Party\Infrastructure\Doctrine\DoctrineParties;
use App\Party\Infrastructure\Doctrine\DoctrinePartyAddresses;
use App\Party\Infrastructure\Doctrine\DoctrinePartyBankAccounts;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Application\TenantMismatch;
use App\Tenancy\Domain\TenantId;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AddressBookPersistenceTest extends KernelTestCase
{
    private const string IBAN = 'DE89370400440532013000';
    private const string OTHER_IBAN = 'GB82WEST12345698765432';

    private EntityManagerInterface $em;
    private TenantContext $context;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $context = $container->get(TenantContext::class);
        $this->em = $em;
        $this->context = $context;

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->context->switchTo(TenantId::generate());
    }

    public function testPartyRoundTripsWithTheNewFields(): void
    {
        $party = $this->company();
        $party->archive(new \DateTimeImmutable('2026-10-30 10:00:00'));
        $party->changePrivateNote('Pays late');
        $party->recordRegistryCheck(new RegistryCheck(RegistrySource::Vies, RegistryResult::Valid, new \DateTimeImmutable('2026-10-30 09:00:00')));
        $this->em->persist($party);
        $this->em->persist(new Party(PartyKind::Person, 'Test Person', null, null, ''));
        $this->em->flush();
        $this->em->clear();

        $parties = $this->em->getRepository(Party::class)->findBy([], ['id' => 'ASC']);

        self::assertSame([PartyRole::Customer, PartyRole::Partner], $parties[0]->roles());
        self::assertTrue($parties[0]->isArchived());
        self::assertSame('2026-10-30 10:00:00', $parties[0]->archivedAt()?->format('Y-m-d H:i:s'));
        self::assertSame('Pays late', $parties[0]->privateNote());
        self::assertSame(14, $parties[0]->invoicingDefaults()->dueDays);
        self::assertSame(RegistryResult::Valid, $parties[0]->viesCheck()?->result);
        self::assertSame('2026-10-30 09:00:00', $parties[0]->viesCheck()->checkedAt->format('Y-m-d H:i:s'));
        self::assertNull($parties[0]->unreliablePayerCheck());
        self::assertSame([], $parties[1]->roles());
        self::assertFalse($parties[1]->isArchived());
        self::assertNull($parties[1]->viesCheck());
    }

    public function testChildrenRoundTrip(): void
    {
        $company = $this->company();
        $person = new Party(PartyKind::Person, 'Test Person', null, null, '');
        $this->em->persist($company);
        $this->em->persist($person);
        $this->em->flush();
        $this->addresses()->add(new PartyAddress($company, AddressType::Billing, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), 'HQ'));
        $this->addresses()->add(new PartyAddress($company, AddressType::Delivery, new Address('Dock 2', 'Other City', '200 00', 'CZ')));
        $this->addresses()->add(new PartyAddress($company, AddressType::Delivery, new Address('Dock 3', 'Other City', '200 00', 'CZ')));
        $this->em->persist(new PartyEmail($company, 'invoices@example.com', EmailPurpose::Invoices));
        $this->bankAccounts()->add(new PartyBankAccount($company, Iban::fromString(self::IBAN), 'COBADEFF', 'Main', true));
        $this->links()->add(new ContactLink($company, $person, 'Accountant', true));
        $this->em->flush();
        $this->em->clear();

        $addresses = $this->em->getRepository(PartyAddress::class)->findBy([], ['id' => 'ASC']);
        self::assertCount(3, $addresses);
        self::assertSame(AddressType::Billing, $addresses[0]->type());
        self::assertSame('HQ', $addresses[0]->label());
        self::assertSame('Example City', $addresses[0]->address()->city);
        self::assertSame('Example Company', $addresses[0]->party()->displayName());

        $email = $this->em->getRepository(PartyEmail::class)->findAll()[0];
        self::assertSame(EmailPurpose::Invoices, $email->purpose());

        $account = $this->em->getRepository(PartyBankAccount::class)->findAll()[0];
        self::assertSame(self::IBAN, $account->iban()->toString());
        self::assertSame('COBADEFF', $account->bic());
        self::assertTrue($account->isPrimary());

        $link = $this->em->getRepository(ContactLink::class)->findAll()[0];
        self::assertSame('Test Person', $link->person()->displayName());
        self::assertSame('Accountant', $link->position());
        self::assertTrue($link->isPrimary());
    }

    public function testEveryChildTakesTheTenantOfItsParty(): void
    {
        $company = $this->company();
        $person = new Party(PartyKind::Person, 'Test Person', null, null, '');
        $this->em->persist($company);
        $this->em->persist($person);
        $address = new PartyAddress($company, AddressType::Billing, new Address('Example Street 1', 'Example City', '100 00', 'CZ'));
        $email = new PartyEmail($company, 'invoices@example.com', EmailPurpose::Invoices);
        $account = new PartyBankAccount($company, Iban::fromString(self::IBAN));
        $link = new ContactLink($company, $person);
        foreach ([$address, $email, $account, $link] as $child) {
            $this->em->persist($child);
        }
        $this->em->flush();

        foreach ([$address, $email, $account, $link] as $child) {
            self::assertTrue($this->context->current()->equals($child->tenantId()));
        }
    }

    public function testChildrenAreAudited(): void
    {
        $company = $this->company();
        $this->em->persist($company);
        $this->em->flush();
        $this->em->persist(new PartyEmail($company, 'invoices@example.com', EmailPurpose::Invoices));
        $this->em->flush();

        $entries = $this->em->getRepository(AuditEntry::class)->findBy(['entityClass' => PartyEmail::class]);

        self::assertCount(1, $entries);
        self::assertSame([null, 'invoices@example.com'], $entries[0]->changes()['address']);
    }

    public function testCreatingAPartyAlwaysYieldsExactlyOneBillingAddress(): void
    {
        $create = new CreateParty(new DoctrineParties($this->em));
        $billing = new Address('Example Street 1', 'Example City', '100 00', 'CZ');
        $create->create(PartyKind::Company, 'Example Company', new RegistrationNumber('CZ', '12345687'), null, 'invoices@example.com', '', [PartyRole::Customer], $billing);
        $create->create(PartyKind::Person, 'Test Person', null, null, '  ', '', [], $billing);
        $this->em->flush();
        $this->em->clear();

        $counts = $this->em->getConnection()->fetchAllKeyValue("SELECT p.display_name, COUNT(a.id) FROM party p LEFT JOIN party_address a ON a.party_id = p.id AND a.type = 'billing' GROUP BY p.display_name");
        self::assertEquals(['Example Company' => 1, 'Test Person' => 1], $counts);
        $emails = $this->em->getRepository(PartyEmail::class)->findAll();
        self::assertCount(1, $emails);
        self::assertSame(EmailPurpose::General, $emails[0]->purpose());
        self::assertSame('invoices@example.com', $emails[0]->address());
    }

    public function testAnotherBillingAddressAfterCreationIsRejected(): void
    {
        $billing = new Address('Example Street 1', 'Example City', '100 00', 'CZ');
        $party = new CreateParty(new DoctrineParties($this->em))->create(PartyKind::Person, 'Test Person', null, null, '', '', [], $billing);

        $this->expectException(\DomainException::class);
        $this->addresses()->add(new PartyAddress($party, AddressType::Billing, $billing));
    }

    public function testTwoBillingAddressesInOneFlushRaiseADomainException(): void
    {
        $company = $this->company();
        $this->em->persist($company);
        $this->addresses()->add(new PartyAddress($company, AddressType::Billing, new Address('Example Street 1', 'Example City', '100 00', 'CZ')));

        $this->expectException(\DomainException::class);
        $this->addresses()->add(new PartyAddress($company, AddressType::Billing, new Address('Main 2', 'Other City', '200 00', 'CZ')));
    }

    public function testTwoPrimaryAccountsInOneFlushLeaveTheLastOne(): void
    {
        $company = $this->company();
        $this->em->persist($company);
        $this->em->flush();
        $first = new PartyBankAccount($company, Iban::fromString(self::IBAN), null, '', true);
        $second = new PartyBankAccount($company, Iban::fromString(self::OTHER_IBAN), null, '', true);
        $this->bankAccounts()->add($first);
        $this->bankAccounts()->add($second);
        $this->em->flush();

        self::assertFalse($first->isPrimary());
        self::assertTrue($second->isPrimary());
        self::assertSame(1, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM party_bank_account WHERE primary_flag IS NOT NULL'));
    }

    public function testPrimaryAccountsOfANewPartyInOneFlush(): void
    {
        $company = $this->company();
        $this->em->persist($company);
        $this->bankAccounts()->add(new PartyBankAccount($company, Iban::fromString(self::IBAN), null, '', true));
        $this->bankAccounts()->add(new PartyBankAccount($company, Iban::fromString(self::OTHER_IBAN), null, '', true));
        $this->em->flush();

        self::assertSame(1, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM party_bank_account WHERE primary_flag IS NOT NULL'));
    }

    public function testPrimarySwapsInSequence(): void
    {
        $company = $this->company();
        $this->em->persist($company);
        $this->em->flush();
        $accounts = [];
        foreach ([self::IBAN, self::OTHER_IBAN, 'FR1420041010050500013M02606'] as $iban) {
            $accounts[] = new PartyBankAccount($company, Iban::fromString($iban));
            $this->bankAccounts()->add($accounts[array_key_last($accounts)]);
        }
        $this->em->flush();

        foreach ([0, 2, 1, 0] as $index) {
            $this->bankAccounts()->makePrimary($accounts[$index]);
            $this->em->flush();

            foreach ($accounts as $i => $account) {
                self::assertSame($i === $index, $account->isPrimary());
            }
            self::assertSame(1, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM party_bank_account WHERE primary_flag IS NOT NULL'));
        }
    }

    public function testTheDatabaseAlsoRefusesTwoPrimaryAccounts(): void
    {
        $company = $this->company();
        $this->em->persist($company);
        $this->em->persist(new PartyBankAccount($company, Iban::fromString(self::IBAN), null, '', true));
        $this->em->persist(new PartyBankAccount($company, Iban::fromString(self::OTHER_IBAN), null, '', true));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testTwoIdenticalLinksInOneFlushRaiseADomainException(): void
    {
        $company = $this->company();
        $person = new Party(PartyKind::Person, 'Test Person', null, null, '');
        $this->em->persist($company);
        $this->em->persist($person);
        $this->links()->add(new ContactLink($company, $person));

        $this->expectException(\DomainException::class);
        $this->links()->add(new ContactLink($company, $person));
    }

    public function testOnlyTheBillingAddressCarriesTheBillingFlag(): void
    {
        $company = $this->company();
        $this->em->persist($company);
        $this->em->persist(new PartyAddress($company, AddressType::Billing, new Address('Example Street 1', 'Example City', '100 00', 'CZ')));
        $this->em->persist(new PartyAddress($company, AddressType::Delivery, new Address('Dock 2', 'Other City', '200 00', 'CZ')));
        $this->em->flush();

        $flags = $this->em->getConnection()->fetchAllKeyValue('SELECT type, billing_flag FROM party_address');

        self::assertEquals(['billing' => true, 'delivery' => null], $flags);
    }

    public function testChildrenAlwaysBelongToAParty(): void
    {
        foreach ([[PartyAddress::class, 'party_id'], [PartyEmail::class, 'party_id'], [PartyBankAccount::class, 'party_id'], [ContactLink::class, 'company_id'], [ContactLink::class, 'person_id']] as [$class, $column]) {
            $metadata = $this->em->getClassMetadata($class);
            $table = new SchemaTool($this->em)->getSchemaFromMetadata([$metadata])->getTable($metadata->getTableName());

            self::assertTrue($table->getColumn($column)->getNotnull(), $class.'.'.$column);
        }
    }

    public function testASecondBillingAddressIsRejected(): void
    {
        $company = $this->company();
        $this->em->persist($company);
        $this->em->flush();
        $this->addresses()->add(new PartyAddress($company, AddressType::Billing, new Address('Example Street 1', 'Example City', '100 00', 'CZ')));
        $this->em->flush();

        $this->expectException(\DomainException::class);
        $this->addresses()->add(new PartyAddress($company, AddressType::Billing, new Address('Main 2', 'Other City', '200 00', 'CZ')));
    }

    public function testBillingAddressOfADifferentPartyIsAllowed(): void
    {
        $first = $this->company();
        $second = $this->company('Other Company');
        $this->em->persist($first);
        $this->em->persist($second);
        $this->em->flush();

        $this->addresses()->add(new PartyAddress($first, AddressType::Billing, new Address('Example Street 1', 'Example City', '100 00', 'CZ')));
        $this->addresses()->add(new PartyAddress($second, AddressType::Billing, new Address('Main 2', 'Other City', '200 00', 'CZ')));
        $this->em->flush();

        self::assertCount(2, $this->em->getRepository(PartyAddress::class)->findAll());
    }

    public function testTheDatabaseAlsoRefusesASecondBillingAddress(): void
    {
        $company = $this->company();
        $this->em->persist($company);
        $this->em->persist(new PartyAddress($company, AddressType::Billing, new Address('Example Street 1', 'Example City', '100 00', 'CZ')));
        $this->em->persist(new PartyAddress($company, AddressType::Billing, new Address('Main 2', 'Other City', '200 00', 'CZ')));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testANewPartyCanGetItsBillingAddressInTheSameFlush(): void
    {
        $company = $this->company();
        $this->em->persist($company);
        $this->addresses()->add(new PartyAddress($company, AddressType::Billing, new Address('Example Street 1', 'Example City', '100 00', 'CZ')));
        $this->em->flush();

        self::assertCount(1, $this->em->getRepository(PartyAddress::class)->findAll());
    }

    public function testAddingAPrimaryBankAccountDemotesTheOldOne(): void
    {
        $company = $this->company();
        $this->em->persist($company);
        $this->em->flush();
        $old = new PartyBankAccount($company, Iban::fromString(self::IBAN), null, '', true);
        $this->bankAccounts()->add($old);
        $this->em->flush();

        $new = new PartyBankAccount($company, Iban::fromString(self::OTHER_IBAN), null, '', true);
        $this->bankAccounts()->add($new);
        $this->em->flush();
        $this->em->clear();

        $primary = $this->em->getRepository(PartyBankAccount::class)->findBy(['primaryFlag' => true]);
        self::assertCount(1, $primary);
        self::assertSame(self::OTHER_IBAN, $primary[0]->iban()->toString());
    }

    public function testASecondaryAccountLeavesThePrimaryAlone(): void
    {
        $company = $this->company();
        $this->em->persist($company);
        $this->em->flush();
        $this->bankAccounts()->add(new PartyBankAccount($company, Iban::fromString(self::IBAN), null, '', true));
        $this->bankAccounts()->add(new PartyBankAccount($company, Iban::fromString(self::OTHER_IBAN)));
        $this->em->flush();
        $this->em->clear();

        self::assertCount(1, $this->em->getRepository(PartyBankAccount::class)->findBy(['primaryFlag' => true]));
        self::assertCount(2, $this->em->getRepository(PartyBankAccount::class)->findAll());
    }

    public function testMakingAnAccountPrimaryDemotesTheOthers(): void
    {
        $company = $this->company();
        $other = $this->company('Other Company');
        $this->em->persist($company);
        $this->em->persist($other);
        $this->em->flush();
        $first = new PartyBankAccount($company, Iban::fromString(self::IBAN), null, '', true);
        $second = new PartyBankAccount($company, Iban::fromString(self::OTHER_IBAN));
        $foreign = new PartyBankAccount($other, Iban::fromString(self::IBAN), null, '', true);
        foreach ([$first, $second, $foreign] as $account) {
            $this->bankAccounts()->add($account);
        }
        $this->em->flush();

        $this->bankAccounts()->makePrimary($second);
        $this->em->flush();

        self::assertFalse($first->isPrimary());
        self::assertTrue($second->isPrimary());
        self::assertTrue($foreign->isPrimary());
    }

    public function testAPersonCannotBeLinkedToTheSameCompanyTwice(): void
    {
        $company = $this->company();
        $person = new Party(PartyKind::Person, 'Test Person', null, null, '');
        $other = $this->company('Other Company');
        $this->em->persist($company);
        $this->em->persist($person);
        $this->em->persist($other);
        $this->em->flush();
        $this->links()->add(new ContactLink($company, $person));
        $this->em->flush();

        // The same person may work for another company.
        $this->links()->add(new ContactLink($other, $person));
        $this->em->flush();

        $this->expectException(\DomainException::class);
        $this->links()->add(new ContactLink($company, $person, 'Accountant'));
    }

    public function testTheDatabaseAlsoRefusesADuplicateLink(): void
    {
        $company = $this->company();
        $person = new Party(PartyKind::Person, 'Test Person', null, null, '');
        $this->em->persist($company);
        $this->em->persist($person);
        $this->em->persist(new ContactLink($company, $person));
        $this->em->persist(new ContactLink($company, $person));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testAChildOfAPartyOfAnotherTenantIsRejected(): void
    {
        $company = $this->company();
        $this->em->persist($company);
        $this->em->flush();
        $this->context->switchTo(TenantId::generate());

        $this->expectException(TenantMismatch::class);
        $this->em->persist(new PartyEmail($company, 'invoices@example.com', EmailPurpose::Invoices));
    }

    public function testALinkBetweenPartiesOfAnotherTenantIsRejected(): void
    {
        $company = $this->company();
        $person = new Party(PartyKind::Person, 'Test Person', null, null, '');
        $this->em->persist($company);
        $this->em->persist($person);
        $this->em->flush();
        $this->context->switchTo(TenantId::generate());

        $this->expectException(TenantMismatch::class);
        $this->em->persist(new ContactLink($company, $person));
    }

    public function testTheChildrenOfAnotherTenantAreInvisible(): void
    {
        $company = $this->company();
        $this->em->persist($company);
        $this->em->persist(new PartyEmail($company, 'invoices@example.com', EmailPurpose::Invoices));
        $this->em->flush();
        $this->em->clear();

        $this->context->switchTo(TenantId::generate());

        self::assertSame([], $this->em->getRepository(PartyEmail::class)->findAll());
        self::assertSame([], $this->em->getRepository(PartyAddress::class)->findAll());
        self::assertSame([], $this->em->getRepository(PartyBankAccount::class)->findAll());
        self::assertSame([], $this->em->getRepository(ContactLink::class)->findAll());
    }

    private function company(string $name = 'Example Company'): Party
    {
        return new Party(PartyKind::Company, $name, new RegistrationNumber('CZ', '12345687'), VatId::fromString('CZ12345687'), '', [PartyRole::Customer, PartyRole::Partner]);
    }

    private function addresses(): DoctrinePartyAddresses
    {
        return new DoctrinePartyAddresses($this->em);
    }

    private function bankAccounts(): DoctrinePartyBankAccounts
    {
        return new DoctrinePartyBankAccounts($this->em);
    }

    private function links(): DoctrineContactLinks
    {
        return new DoctrineContactLinks($this->em);
    }
}
