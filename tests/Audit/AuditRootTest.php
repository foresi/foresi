<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Audit;

use App\Audit\Domain\AuditAction;
use App\Audit\Domain\AuditEntry;
use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Party\Application\PartyDetails;
use App\Party\Application\PartyHistoryEntry;
use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\ContactLink;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Party\Domain\RegistrationNumber;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Audit\Fixtures\AuditedSecret;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AuditRootTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $context = $container->get(TenantContext::class);
        $this->em = $em;

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $context->switchTo(TenantId::generate());
    }

    public function testAChildChangeIsRecordedUnderItsParty(): void
    {
        $party = $this->company('Example Company');
        $address = new PartyAddress($party, AddressType::Delivery, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), 'Warehouse');
        $this->em->persist($party);
        $this->em->persist($address);
        $this->em->flush();

        $entry = $this->entryFor(PartyAddress::class, $address->id());
        self::assertSame(Party::class, $entry->rootClass());
        self::assertSame($party->id(), $entry->rootId());
        self::assertSame($address->id(), $entry->entityId());

        $party = $this->entryFor(Party::class, $party->id());
        self::assertSame(Party::class, $party->rootClass());
        self::assertSame($party->entityId(), $party->rootId());
    }

    public function testAContactLinkIsRecordedUnderItsCompany(): void
    {
        $company = $this->company('Example Company');
        $person = new Party(PartyKind::Person, 'Example Person', null, null, '', [PartyRole::Customer]);
        $link = new ContactLink($company, $person, 'Director');
        $this->em->persist($company);
        $this->em->persist($person);
        $this->em->persist($link);
        $this->em->flush();

        $entry = $this->entryFor(ContactLink::class, $link->id());
        self::assertSame($company->id(), $entry->rootId());
    }

    public function testARemovedChildStaysInTheHistoryOfItsParty(): void
    {
        $party = $this->company('Example Company');
        $address = new PartyAddress($party, AddressType::Delivery, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), 'Warehouse');
        $this->em->persist($party);
        $this->em->persist($address);
        $this->em->flush();
        $this->em->remove($address);
        $this->em->flush();

        $entries = $this->em->createQuery(
            'SELECT a FROM '.AuditEntry::class.' a WHERE a.rootClass = :class AND a.rootId = :id AND a.entityClass = :child ORDER BY a.occurredAt, a.id',
        )->setParameter('class', Party::class)->setParameter('id', $party->id())->setParameter('child', PartyAddress::class)->getResult();

        self::assertContainsOnlyInstancesOf(AuditEntry::class, $entries);
        $actions = array_map(static fn (AuditEntry $e): AuditAction => $e->action(), $entries);
        self::assertContains(AuditAction::Created, $actions);
        self::assertContains(AuditAction::Deleted, $actions);
        self::assertSame(
            2,
            (int) $this->em->createQuery('SELECT COUNT(a.id) FROM '.AuditEntry::class.' a WHERE a.rootClass = :class AND a.rootId = :id AND a.entityClass = :child')
                ->setParameter('class', Party::class)->setParameter('id', $party->id())->setParameter('child', PartyAddress::class)->getSingleScalarResult(),
        );
    }

    public function testThePartyHistoryHasTheRemovedChildAndBothSidesOfAContactLink(): void
    {
        $company = $this->company('Example Company');
        $person = new Party(PartyKind::Person, 'Example Person', null, null, '', [PartyRole::Customer]);
        $address = new PartyAddress($company, AddressType::Delivery, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), 'Warehouse');
        $link = new ContactLink($company, $person, 'Director');
        foreach ([$company, $person, $address, $link] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->em->remove($address);
        $this->em->flush();

        $details = self::getContainer()->get(PartyDetails::class);
        $companySubjects = array_map(static fn (PartyHistoryEntry $e): string => $e->subject.':'.$e->action, $details->history($company));
        sort($companySubjects);
        self::assertSame(['address:created', 'address:deleted', 'contact:created', 'party:created'], $companySubjects);
        $personSubjects = array_map(static fn (PartyHistoryEntry $e): string => $e->subject.':'.$e->action, $details->history($person));
        sort($personSubjects);
        self::assertSame(['contact:created', 'party:created'], $personSubjects);
    }

    public function testAnEntityWithoutARootIsItsOwnRoot(): void
    {
        $secret = new AuditedSecret('first', 'hunter2');
        $this->em->persist($secret);
        $this->em->flush();

        $id = $this->em->getUnitOfWork()->getSingleIdentifierValue($secret);
        self::assertIsString($id);
        $entry = $this->entryFor(AuditedSecret::class, $id);
        self::assertSame(AuditedSecret::class, $entry->rootClass());
        self::assertSame($entry->entityId(), $entry->rootId());
    }

    public function testAMembershipIsItsOwnRoot(): void
    {
        $user = new User('testing@example.com', 'hash', 'Example');
        $party = $this->company('Example Company');
        $membership = new Membership($user, Role::Owner, $party);
        $this->em->persist($user);
        $this->em->persist($party);
        $this->em->persist($membership);
        $this->em->flush();

        $entry = $this->entryFor(Membership::class, $membership->id());
        self::assertSame(Membership::class, $entry->rootClass());
        self::assertSame($membership->id(), $entry->rootId());
    }

    private function company(string $name): Party
    {
        return new Party(PartyKind::Company, $name, new RegistrationNumber('CZ', '12345695'), null, '', [PartyRole::Customer]);
    }

    private function entryFor(string $class, string $id): AuditEntry
    {
        $entry = $this->em->getRepository(AuditEntry::class)->findOneBy(['entityClass' => $class, 'entityId' => $id]);
        self::assertInstanceOf(AuditEntry::class, $entry);

        return $entry;
    }
}
