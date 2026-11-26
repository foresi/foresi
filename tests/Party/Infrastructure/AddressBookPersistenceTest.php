<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Infrastructure;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Audit\Domain\AuditEntry;
use Foresi\Party\Application\CreateParty;
use Foresi\Party\Application\PartyAccess;
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
use Foresi\Party\Domain\RegistryCheck;
use Foresi\Party\Domain\RegistryResult;
use Foresi\Party\Domain\RegistrySource;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Infrastructure\Doctrine\DoctrineContactLinks;
use Foresi\Party\Infrastructure\Doctrine\DoctrineParties;
use Foresi\Party\Infrastructure\Doctrine\DoctrinePartyAddresses;
use Foresi\Party\Infrastructure\Doctrine\DoctrinePartyBankAccounts;
use Foresi\Party\Infrastructure\Doctrine\DoctrineTransactionalWork;
use Foresi\Shared\Application\Event\EventOutbox;
use Foresi\Shared\Application\Field\CustomFieldWrites;
use Foresi\Shared\Contract\Event\EventRecorder;
use Foresi\Shared\Infrastructure\Doctrine\DoctrineUnitOfWork;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AddressBookPersistenceTest extends KernelTestCase
{
    private const string IBAN = 'DE89370400440532013000';
    private const string OTHER_IBAN = 'GB82WEST12345698765432';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $this->em = $em;

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
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
        $create = new CreateParty(new DoctrineParties($this->em), $this->transaction(), self::getContainer()->get(PartyAccess::class), self::getContainer()->get(EventRecorder::class), self::getContainer()->get(CustomFieldWrites::class));
        $billing = new Address('Example Street 1', 'Example City', '100 00', 'CZ');
        $create->create(PartyKind::Company, 'Example Company', new RegistrationNumber('CZ', '12345695'), null, 'invoices@example.com', '', [PartyRole::Customer], $billing);
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
        $party = new CreateParty(new DoctrineParties($this->em), $this->transaction(), self::getContainer()->get(PartyAccess::class), self::getContainer()->get(EventRecorder::class), self::getContainer()->get(CustomFieldWrites::class))->create(PartyKind::Person, 'Test Person', null, null, '', '', [], $billing);

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

    public function testMakingAccountsPrimaryTwiceBeforeOneFlushLeavesOne(): void
    {
        $company = $this->company();
        $this->em->persist($company);
        $this->em->flush();
        $accounts = [];
        foreach ([self::IBAN, self::OTHER_IBAN, 'FR1420041010050500013M02606'] as $iban) {
            $accounts[] = new PartyBankAccount($company, Iban::fromString($iban));
            $this->bankAccounts()->add($accounts[array_key_last($accounts)]);
        }

        $this->bankAccounts()->makePrimary($accounts[0]);
        $this->bankAccounts()->makePrimary($accounts[1]);
        $this->em->flush();

        self::assertFalse($accounts[0]->isPrimary());
        self::assertTrue($accounts[1]->isPrimary());
        self::assertSame(1, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM party_bank_account WHERE primary_flag IS NOT NULL'));
    }

    public function testAFailureAfterTheDemotionRollsBothChangesBack(): void
    {
        $company = $this->company();
        $this->em->persist($company);
        $this->em->flush();
        $old = new PartyBankAccount($company, Iban::fromString(self::IBAN), null, '', true);
        $this->bankAccounts()->add($old);
        $this->em->getEventManager()->addEventListener(Events::prePersist, new class {
            public function prePersist(PrePersistEventArgs $args): void
            {
                if ($args->getObject() instanceof PartyBankAccount) {
                    throw new \RuntimeException('Storing the new account failed.');
                }
            }
        });

        try {
            $this->bankAccounts()->add(new PartyBankAccount($company, Iban::fromString(self::OTHER_IBAN), null, '', true));
            self::fail('The failure should surface.');
        } catch (\RuntimeException) {
        }

        self::assertEquals([['DE89370400440532013000', 1]], $this->em->getConnection()->fetchAllNumeric('SELECT iban, primary_flag FROM party_bank_account'));
    }

    public function testAnInnerUnitFailingLeavesThePendingChangeOfTheOuterUnit(): void
    {
        $work = $this->transaction();
        $em = $this->em;

        $failed = false;

        $work->run(function () use ($work, $em, &$failed): void {
            $em->persist($this->company('Outer Company'));
            try {
                $work->run(static function (): never {
                    throw new \RuntimeException('The inner unit failed.');
                });
            } catch (\RuntimeException) {
                $failed = true;
            }
            self::assertTrue($em->isOpen(), 'the outer unit keeps its session');
        });

        self::assertTrue($failed, 'the inner failure surfaced');

        self::assertSame(['Outer Company'], $this->em->getConnection()->fetchFirstColumn('SELECT display_name FROM party'));
    }

    public function testAnInnerUnitFlushesIntoTheOuterOneWhichCommitsIt(): void
    {
        $work = $this->transaction();
        $connection = $this->em->getConnection();

        $work->run(function () use ($work, $connection): void {
            $work->run(function (): void {
                $this->em->persist($this->company('Inner Company'));
            });
            self::assertSame(1, $connection->getTransactionNestingLevel(), 'the inner unit opens no transaction of its own');
            self::assertEquals(1, $connection->fetchOne('SELECT COUNT(*) FROM party'), 'it flushes into the outer one, so its caller can read what it made');
        });

        self::assertEquals(1, $connection->fetchOne('SELECT COUNT(*) FROM party'));
    }

    public function testCreatingAPartyIsAtomic(): void
    {
        $this->em->getEventManager()->addEventListener(Events::prePersist, new class {
            public function prePersist(PrePersistEventArgs $args): void
            {
                if ($args->getObject() instanceof PartyAddress) {
                    throw new \RuntimeException('Storing the address failed.');
                }
            }
        });

        try {
            new CreateParty(new DoctrineParties($this->em), $this->transaction(), self::getContainer()->get(PartyAccess::class), self::getContainer()->get(EventRecorder::class), self::getContainer()->get(CustomFieldWrites::class))->create(PartyKind::Person, 'Test Person', null, null, '', '', [], new Address('Example Street 1', 'Example City', '100 00', 'CZ'));
            self::fail('The failure should surface.');
        } catch (\RuntimeException) {
        }

        self::assertSame(0, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM party'));
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

    private function company(string $name = 'Example Company'): Party
    {
        return new Party(PartyKind::Company, $name, new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), '', [PartyRole::Customer, PartyRole::Partner]);
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

    private function transaction(): DoctrineTransactionalWork
    {
        $outbox = new class implements EventOutbox {
            public function committed(): void
            {
            }

            public function rolledBack(): void
            {
            }
        };

        return new DoctrineTransactionalWork(new DoctrineUnitOfWork($this->em, self::getContainer()->get('doctrine'), $outbox));
    }
}
