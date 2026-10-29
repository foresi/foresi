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
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Party\Domain\Address;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\InvoicingDefaults;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Currency;
use App\Tenancy\Application\NoActiveTenant;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Application\TenantMismatch;
use App\Tenancy\Domain\TenantId;
use App\Tests\Audit\Fixtures\AuditedGroup;
use App\Tests\Audit\Fixtures\AuditedSecret;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class AuditLogTest extends KernelTestCase
{
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
    }

    public function testCreateIsLoggedWithGeneratedIdAndValues(): void
    {
        $this->context->switchTo(TenantId::generate());
        $party = $this->party();
        $this->em->persist($party);
        $this->em->flush();

        $entries = $this->entries();
        self::assertCount(1, $entries);
        self::assertSame(Party::class, $entries[0]->entityClass());
        self::assertSame((string) $this->partyId($party), $entries[0]->entityId());
        self::assertSame(AuditAction::Created, $entries[0]->action());
        self::assertSame($this->context->current()->toString(), $entries[0]->tenantId()->toString());
        $changes = $entries[0]->changes();
        self::assertSame([null, 'Example Company'], $changes['displayName']);
        self::assertSame([null, 'company'], $changes['kind']);
        self::assertSame([null, 'CZ12345687'], $changes['vatId']);
        self::assertSame([null, 14], $changes['invoicingDefaults.dueDays']);
        self::assertSame([null, ['customer']], $changes['roles']);
        self::assertArrayNotHasKey('id', $changes);
        self::assertArrayNotHasKey('tenantId', $changes);
    }

    public function testUpdateWritesFieldDiff(): void
    {
        $this->context->switchTo(TenantId::generate());
        $party = $this->party();
        $this->em->persist($party);
        $this->em->flush();

        $this->setProperty($party, 'displayName', 'Renamed Party');
        $this->em->flush();

        $updates = $this->entries(AuditAction::Updated);
        self::assertCount(1, $updates);
        self::assertSame(['displayName' => ['Example Company', 'Renamed Party']], $updates[0]->changes());
        self::assertSame((string) $this->partyId($party), $updates[0]->entityId());
    }

    public function testEmbeddedAndEnumChangesAreScalars(): void
    {
        $this->context->switchTo(TenantId::generate());
        $party = $this->party();
        $this->em->persist($party);
        $this->em->flush();

        $this->setProperty($party, 'invoicingDefaults', new InvoicingDefaults(30, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, false));
        $this->setProperty($party, 'kind', PartyKind::Person);
        $this->setProperty($party, 'vatId', null);
        $this->em->flush();

        $changes = $this->entries(AuditAction::Updated)[0]->changes();
        ksort($changes);
        self::assertSame(['invoicingDefaults.dueDays' => [14, 30], 'kind' => ['company', 'person'], 'vatId' => ['CZ12345687', null]], $changes);
    }

    public function testFlushWithoutChangesWritesNothing(): void
    {
        $this->context->switchTo(TenantId::generate());
        $this->em->persist($this->party());
        $this->em->flush();
        $this->em->flush();

        self::assertCount(1, $this->entries());
    }

    public function testDeleteIsLogged(): void
    {
        $this->context->switchTo(TenantId::generate());
        $party = $this->party();
        $this->em->persist($party);
        $this->em->flush();
        $id = (string) $this->partyId($party);

        $this->em->remove($party);
        $this->em->flush();

        $deleted = $this->entries(AuditAction::Deleted);
        self::assertCount(1, $deleted);
        self::assertSame($id, $deleted[0]->entityId());
        self::assertSame(['Example Company', null], $deleted[0]->changes()['displayName']);
        self::assertSame(['CZ12345687', null], $deleted[0]->changes()['vatId']);
        self::assertArrayNotHasKey('id', $deleted[0]->changes());
    }

    public function testAuditEntriesCannotBeUpdated(): void
    {
        $this->context->switchTo(TenantId::generate());
        $this->em->persist($this->party());
        $this->em->flush();

        $entry = $this->entries()[0];
        $this->setProperty($entry, 'changes', ['displayName' => ['a', 'b']]);

        $this->expectException(\LogicException::class);
        $this->em->flush();
    }

    public function testAuditEntriesCannotBeRemoved(): void
    {
        $this->context->switchTo(TenantId::generate());
        $this->em->persist($this->party());
        $this->em->flush();

        $this->em->remove($this->entries()[0]);

        $this->expectException(\LogicException::class);
        $this->em->flush();
    }

    public function testEntriesAreTenantScoped(): void
    {
        $a = TenantId::generate();
        $b = TenantId::generate();
        $this->context->switchTo($a);
        $partyA = $this->party();
        $this->em->persist($partyA);
        $this->em->flush();
        $this->setProperty($partyA, 'displayName', 'Renamed A');
        $this->em->flush();
        $this->context->switchTo($b);
        $this->em->persist($this->party());
        $this->em->flush();

        $visibleToB = $this->entries();
        $this->context->switchTo($a);
        self::assertCount(1, $visibleToB);
        self::assertCount(2, $this->entries());
        foreach ($this->entries() as $entry) {
            self::assertTrue($a->equals($entry->tenantId()));
        }

        $this->context->reset();
        $this->expectException(NoActiveTenant::class);
        $this->entries();
    }

    public function testSensitiveFieldsAreNeverWritten(): void
    {
        $this->context->switchTo(TenantId::generate());
        $secret = new AuditedSecret('first', 'hunter2');
        $this->em->persist($secret);
        $this->em->flush();
        $secret->changeSecret('hunter3');
        $this->em->flush();
        $secret->changeLabel('second');
        $secret->changeSecret('hunter4');
        $this->em->flush();
        $this->em->remove($secret);
        $this->em->flush();

        $entries = $this->entries();
        self::assertCount(3, $entries);
        self::assertSame(['label' => ['first', 'second']], $entries[1]->changes());
        $json = json_encode(array_map(static fn (AuditEntry $e): array => $e->changes(), $entries), \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('hunter', $json);
        self::assertStringNotContainsString('secret', $json);
    }

    public function testChangeOfOnlyExcludedFieldWritesNothing(): void
    {
        $this->context->switchTo(TenantId::generate());
        $secret = new AuditedSecret('first', 'hunter2');
        $this->em->persist($secret);
        $this->em->flush();
        $secret->changeSecret('hunter3');
        $this->em->flush();

        self::assertCount(1, $this->entries());
    }

    public function testLegalEntityAndMembershipStoreStableScalars(): void
    {
        $this->context->switchTo(TenantId::generate());
        $party = $this->party();
        $user = new User('testing@example.com', 'hash-that-must-not-matter', 'Example Company');
        $this->em->persist($user);
        $this->em->persist($party);
        $this->em->persist(new LegalEntity(
            'Example Company',
            new RegistrationNumber('CZ', '12345687'),
            null,
            new Address('Example Street 1', 'Example City', '100 00', 'CZ'),
            VatStatus::IdentifiedPerson,
            'Register entry 1',
            ['DE89370400440532013000'],
        ));
        $this->em->persist(new Membership($user, Role::Owner, $party));
        $this->em->flush();

        $byClass = [];
        foreach ($this->entries() as $entry) {
            $byClass[$entry->entityClass()] = $entry->changes();
        }
        self::assertSame([null, ['DE89370400440532013000']], $byClass[LegalEntity::class]['bankAccounts']);
        self::assertSame([null, 'identified_person'], $byClass[LegalEntity::class]['vatStatus']);
        self::assertSame([null, '12345687'], $byClass[LegalEntity::class]['registrationNumber.value']);
        self::assertSame([null, $this->partyId($party)], $byClass[Membership::class]['party']);
        self::assertSame([null, $user->id()], $byClass[Membership::class]['user']);
        self::assertSame([null, 'owner'], $byClass[Membership::class]['role']);
        self::assertStringNotContainsString('hash-that', json_encode($byClass, \JSON_THROW_ON_ERROR));
    }

    public function testActingUserIsRecordedWhenSignedIn(): void
    {
        $this->context->switchTo(TenantId::generate());
        $this->em->persist($this->party());
        $this->em->flush();

        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        $user = new SecurityUser(42, 'testing@example.com', 'hash');
        $tokens->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
        $party = $this->em->getRepository(Party::class)->findAll()[0];
        $this->setProperty($party, 'phone', '+420111111111');
        $this->em->flush();

        $entries = $this->entries();
        self::assertNull($entries[0]->userId());
        self::assertSame(42, $entries[1]->userId());
    }

    public function testCreatedEntryUsesTheEntitysTenant(): void
    {
        $tenant = TenantId::generate();
        $this->context->switchTo($tenant);
        $party = $this->party();
        $this->em->persist($party);
        $this->em->flush();

        self::assertTrue($party->tenantId()->equals($this->entries()[0]->tenantId()));
    }

    public function testCreatedEntryRejectsTenantMismatch(): void
    {
        $this->context->switchTo(TenantId::generate());
        $this->em->persist($this->party());
        $this->setProperty($this->context, 'current', TenantId::generate());

        $this->expectException(TenantMismatch::class);
        $this->em->flush();
    }

    public function testUpdateOfAnotherTenantsEntityIsRejected(): void
    {
        $this->context->switchTo(TenantId::generate());
        $secret = new AuditedSecret('first', 'x');
        $this->em->persist($secret);
        $this->em->flush();

        // Switching the tenant detaches managed entities, so swap the active tenant underneath them.
        $this->setProperty($this->context, 'current', TenantId::generate());
        $secret->changeLabel('second');

        $this->expectException(TenantMismatch::class);
        $this->em->flush();
    }

    public function testRemovalOfAnotherTenantsEntityIsRejected(): void
    {
        $this->context->switchTo(TenantId::generate());
        $secret = new AuditedSecret('first', 'x');
        $this->em->persist($secret);
        $this->em->flush();

        $this->setProperty($this->context, 'current', TenantId::generate());
        $this->em->remove($secret);

        $this->expectException(TenantMismatch::class);
        $this->em->flush();
    }

    public function testCollectionChangesOfAuditedEntitiesAreRejected(): void
    {
        $this->context->switchTo(TenantId::generate());
        $secret = new AuditedSecret('first', 'x');
        $group = new AuditedGroup();
        $this->em->persist($secret);
        $this->em->persist($group);
        $this->em->flush();

        $group->add($secret);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('collections of audited entities');
        $this->em->flush();
    }

    private function party(): Party
    {
        return new Party(
            PartyKind::Company,
            'Example Company',
            new RegistrationNumber('CZ', '12345687'),
            VatId::fromString('CZ12345687'),
            'testing@example.com',
            '+420000000000',
            [PartyRole::Customer],
        );
    }

    private function partyId(Party $party): int
    {
        $id = $this->em->getUnitOfWork()->getSingleIdentifierValue($party);
        self::assertIsInt($id);

        return $id;
    }

    /**
     * @return list<AuditEntry>
     */
    private function entries(?AuditAction $action = null): array
    {
        $criteria = $action instanceof AuditAction ? ['action' => $action] : [];

        return $this->em->getRepository(AuditEntry::class)->findBy($criteria, ['id' => 'ASC']);
    }

    private function setProperty(object $object, string $property, mixed $value): void
    {
        new \ReflectionProperty($object, $property)->setValue($object, $value);
    }
}
