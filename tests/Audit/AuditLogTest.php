<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Audit;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Audit\Domain\ActorKind;
use Foresi\Audit\Domain\AuditAction;
use Foresi\Audit\Domain\AuditEntry;
use Foresi\Identity\Application\ApiTokenGrant;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\ApiFirewalls;
use Foresi\Identity\Infrastructure\Security\SecurityApiTokenContext;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Invoicing\Contract\DocumentLanguage;
use Foresi\Invoicing\Contract\InvoicingDefaults;
use Foresi\Invoicing\Contract\PaymentMethod;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\FirmBankAccount;
use Foresi\Party\Domain\Iban;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Tests\Audit\Fixtures\AuditedGroup;
use Foresi\Tests\Audit\Fixtures\AuditedList;
use Foresi\Tests\Audit\Fixtures\AuditedListItem;
use Foresi\Tests\Audit\Fixtures\AuditedSecret;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

final class AuditLogTest extends KernelTestCase
{
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

    public function testCreateIsLoggedWithGeneratedIdAndValues(): void
    {
        $party = $this->party();
        $this->em->persist($party);
        $this->em->flush();

        $entries = $this->entries();
        self::assertCount(1, $entries);
        self::assertSame(Party::class, $entries[0]->entityClass());
        self::assertSame($this->partyId($party), $entries[0]->entityId());
        self::assertSame(AuditAction::Created, $entries[0]->action());
        $changes = $entries[0]->changes();
        self::assertSame([null, 'Example Company'], $changes['displayName']);
        self::assertSame([null, 'company'], $changes['kind']);
        self::assertSame([null, 'CZ12345695'], $changes['vatId']);
        self::assertSame([null, 14], $changes['invoicingDefaults.dueDays']);
        self::assertSame([null, ['customer']], $changes['roles']);
        self::assertArrayNotHasKey('id', $changes);
    }

    public function testUpdateWritesFieldDiff(): void
    {
        $party = $this->party();
        $this->em->persist($party);
        $this->em->flush();

        $this->setProperty($party, 'displayName', 'Renamed Party');
        $this->em->flush();

        $updates = $this->entries(AuditAction::Updated);
        self::assertCount(1, $updates);
        self::assertSame(['displayName' => ['Example Company', 'Renamed Party']], $updates[0]->changes());
        self::assertSame($this->partyId($party), $updates[0]->entityId());
    }

    public function testEmbeddedAndEnumChangesAreScalars(): void
    {
        $party = $this->party();
        $this->em->persist($party);
        $this->em->flush();

        $this->setProperty($party, 'invoicingDefaults', new InvoicingDefaults(30, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, false));
        $this->setProperty($party, 'kind', PartyKind::Person);
        $this->setProperty($party, 'vatId', null);
        $this->em->flush();

        $changes = $this->entries(AuditAction::Updated)[0]->changes();
        ksort($changes);
        self::assertSame(['invoicingDefaults.dueDays' => [14, 30], 'kind' => ['company', 'person'], 'vatId' => ['CZ12345695', null]], $changes);
    }

    public function testFlushWithoutChangesWritesNothing(): void
    {
        $this->em->persist($this->party());
        $this->em->flush();
        $this->em->flush();

        self::assertCount(1, $this->entries());
    }

    public function testDeleteIsLogged(): void
    {
        $party = $this->party();
        $this->em->persist($party);
        $this->em->flush();
        $id = $this->partyId($party);

        $this->em->remove($party);
        $this->em->flush();

        $deleted = $this->entries(AuditAction::Deleted);
        self::assertCount(1, $deleted);
        self::assertSame($id, $deleted[0]->entityId());
        self::assertSame(['Example Company', null], $deleted[0]->changes()['displayName']);
        self::assertSame(['CZ12345695', null], $deleted[0]->changes()['vatId']);
        self::assertArrayNotHasKey('id', $deleted[0]->changes());
    }

    public function testAuditEntriesCannotBeUpdated(): void
    {
        $this->em->persist($this->party());
        $this->em->flush();

        $entry = $this->entries()[0];
        $this->setProperty($entry, 'changes', ['displayName' => ['a', 'b']]);

        $this->expectException(\LogicException::class);
        $this->em->flush();
    }

    public function testAuditEntriesCannotBeRemoved(): void
    {
        $this->em->persist($this->party());
        $this->em->flush();

        $this->em->remove($this->entries()[0]);

        $this->expectException(\LogicException::class);
        $this->em->flush();
    }

    public function testSensitiveFieldsAreNeverWritten(): void
    {
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
        $secret = new AuditedSecret('first', 'hunter2');
        $this->em->persist($secret);
        $this->em->flush();
        $secret->changeSecret('hunter3');
        $this->em->flush();

        self::assertCount(1, $this->entries());
    }

    public function testLegalEntityAndMembershipStoreStableScalars(): void
    {
        $party = $this->party();
        $user = new User('testing@example.com', 'hash-that-must-not-matter', 'Example Company');
        $this->em->persist($user);
        $this->em->persist($party);
        $firm = new LegalEntity(
            'Example Company',
            new RegistrationNumber('CZ', '12345695'),
            null,
            new Address('Example Street 1', 'Example City', '100 00', 'CZ'),
            VatStatus::IdentifiedPerson,
            'Register entry 1',
        );
        $this->em->persist($firm);
        $this->em->persist(FirmBankAccount::open(Iban::fromString('DE89 3704 0044 0532 0130 00'), Currency::EUR, '', 0));
        $this->em->persist(new Membership($user, Role::Owner, $party));
        $this->em->flush();

        $byClass = [];
        foreach ($this->entries() as $entry) {
            $byClass[$entry->entityClass()] = $entry->changes();
        }
        self::assertSame([null, 'DE89370400440532013000'], $byClass[FirmBankAccount::class]['iban']);
        self::assertSame([null, 'EUR'], $byClass[FirmBankAccount::class]['currency']);
        self::assertArrayNotHasKey('firm', $byClass[FirmBankAccount::class]);
        self::assertSame([null, 'identified_person'], $byClass[LegalEntity::class]['vatStatus']);
        self::assertSame([null, '12345695'], $byClass[LegalEntity::class]['registrationNumber.value']);
        self::assertSame([null, $this->partyId($party)], $byClass[Membership::class]['party']);
        self::assertSame([null, $user->id()], $byClass[Membership::class]['user']);
        self::assertSame([null, 'owner'], $byClass[Membership::class]['role']);
        self::assertStringNotContainsString('hash-that', json_encode($byClass, \JSON_THROW_ON_ERROR));
    }

    public function testActingUserIsRecordedWhenSignedIn(): void
    {
        $this->em->persist($this->party());
        $this->em->flush();

        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        $user = new SecurityUser('019a0000-0000-7000-8000-00000000002a', 'testing@example.com', 'hash');
        $tokens->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
        $party = $this->em->getRepository(Party::class)->findAll()[0];
        $this->setProperty($party, 'phone', '+420111111111');
        $this->em->flush();

        $entries = $this->entries();
        self::assertNull($entries[0]->userId());
        self::assertSame('019a0000-0000-7000-8000-00000000002a', $entries[1]->userId());
    }

    public function testTheKindOfActorIsRecorded(): void
    {
        $this->em->persist($this->party());
        $this->em->flush();

        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        $user = new SecurityUser('019a0000-0000-7000-8000-00000000002a', 'testing@example.com', 'hash');
        $party = $this->em->getRepository(Party::class)->findAll()[0];

        $tokens->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
        $this->setProperty($party, 'phone', '+420111111111');
        $this->em->flush();

        $mcp = new PostAuthenticationToken($user, ApiFirewalls::MCP, ['ROLE_USER']);
        $mcp->setAttribute(SecurityApiTokenContext::ATTRIBUTE, new ApiTokenGrant($user->id(), '019a0000-0000-7000-8000-0000000000aa', 'Agent', [ApiScope::read()], new \DateTimeImmutable('2030-01-01')));
        $tokens->setToken($mcp);
        $this->setProperty($party, 'phone', '+420222222222');
        $this->em->flush();

        $entries = $this->entries();
        self::assertSame(ActorKind::System, $entries[0]->actorKind());
        self::assertSame(ActorKind::User, $entries[1]->actorKind());
        self::assertSame(ActorKind::McpAgent, $entries[2]->actorKind());
    }

    public function testCollectionChangesOfAuditedEntitiesAreRejected(): void
    {
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

    public function testItemsOfAnAuditedEntityAreAuditedOnTheirOwn(): void
    {
        $list = new AuditedList();
        $first = $list->add('first');
        $this->em->persist($list);
        $this->em->flush();

        $list->add('second');
        $first->relabel('renamed');
        $this->em->flush();
        $list->remove($first);
        $this->em->flush();

        $items = array_values(array_filter($this->entries(), static fn (AuditEntry $entry): bool => AuditedListItem::class === $entry->entityClass()));
        self::assertSame(
            [[AuditAction::Created, [null, 'first']], [AuditAction::Created, [null, 'second']], [AuditAction::Updated, ['first', 'renamed']], [AuditAction::Deleted, ['renamed', null]]],
            array_map(static fn (AuditEntry $entry): array => [$entry->action(), $entry->changes()['label']], $items),
        );
        self::assertSame([AuditedList::class], array_values(array_unique(array_map(static fn (AuditEntry $entry): string => $entry->rootClass(), $items))));
    }

    private function party(): Party
    {
        return new Party(
            PartyKind::Company,
            'Example Company',
            new RegistrationNumber('CZ', '12345695'),
            VatId::fromString('CZ12345695'),
            '+420000000000',
            [PartyRole::Customer]);
    }

    private function partyId(Party $party): string
    {
        $id = $this->em->getUnitOfWork()->getSingleIdentifierValue($party);
        self::assertIsString($id);

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
