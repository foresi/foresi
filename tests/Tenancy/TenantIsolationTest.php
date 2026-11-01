<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Shared\Domain\Id;
use App\Tenancy\Application\NoActiveTenant;
use App\Tenancy\Application\TenantAlreadySet;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Application\TenantMismatch;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use App\Tenancy\Infrastructure\Doctrine\TenantFilter;
use App\Tests\Tenancy\Fixtures\TestAttachment;
use App\Tests\Tenancy\Fixtures\TestNote;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TenantIsolationTest extends KernelTestCase
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

    public function testQueriesOnlyReturnCurrentTenantRows(): void
    {
        [$a, $b] = $this->seedTwoTenants();

        $this->context->switchTo($a);
        $texts = array_map(static fn (TestNote $n): string => $n->text(), $this->em->getRepository(TestNote::class)->findAll());
        sort($texts);

        self::assertSame(['a1', 'a2'], $texts);

        $this->context->switchTo($b);
        self::assertCount(1, $this->em->getRepository(TestNote::class)->findAll());
    }

    public function testDqlCountIsScoped(): void
    {
        [$a] = $this->seedTwoTenants();
        $this->context->switchTo($a);

        $count = $this->em->createQuery('SELECT COUNT(n) FROM '.TestNote::class.' n')->getSingleScalarResult();

        self::assertSame(2, (int) $count);
    }

    public function testReadsFailClosedWithoutTenant(): void
    {
        $this->seedTwoTenants();
        $this->context->reset();

        try {
            $this->em->getRepository(TestNote::class)->findAll();
            self::fail('findAll() must throw without an active tenant.');
        } catch (NoActiveTenant) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(NoActiveTenant::class);
        $this->em->createQuery('SELECT COUNT(n) FROM '.TestNote::class.' n')->getSingleScalarResult();
    }

    /**
     * Documents the mechanism TenantFilterBypass relies on: the filter is a
     * switchable Doctrine filter. Production code must go through the bypass.
     */
    public function testFilterCanBeSwitchedOffForTheBypassToUse(): void
    {
        [$a] = $this->seedTwoTenants();
        $this->context->switchTo($a);

        $this->em->getFilters()->disable(TenantFilter::NAME);

        self::assertCount(3, $this->em->getRepository(TestNote::class)->findAll());
    }

    public function testDqlDeleteIsScopedToActiveTenant(): void
    {
        [$a, $b] = $this->seedTwoTenants();
        $this->context->switchTo($a);

        $affected = $this->em->createQuery('DELETE FROM '.TestNote::class.' n')->execute();

        self::assertSame(2, $affected);
        $this->context->switchTo($b);
        self::assertCount(1, $this->em->getRepository(TestNote::class)->findAll());
    }

    public function testDqlDeleteCannotReachAnotherTenantsRow(): void
    {
        [$a, $b] = $this->seedTwoTenants();
        $this->context->switchTo($b);
        $id = $this->em->getRepository(TestNote::class)->findOneBy(['text' => 'b1'])?->id();
        $this->context->switchTo($a);

        $affected = $this->em->createQuery('DELETE FROM '.TestNote::class.' n WHERE n.id = :id')
            ->setParameter('id', $id)
            ->execute();

        self::assertSame(0, $affected);
        $this->context->switchTo($b);
        self::assertCount(1, $this->em->getRepository(TestNote::class)->findAll());
    }

    public function testDqlUpdateCannotReachAnotherTenantsRow(): void
    {
        [$a, $b] = $this->seedTwoTenants();
        $this->context->switchTo($a);

        $affected = $this->em->createQuery('UPDATE '.TestNote::class.' n SET n.text = :text')
            ->setParameter('text', 'changed')
            ->execute();

        self::assertSame(2, $affected);
        $this->em->clear();
        $this->context->switchTo($b);
        self::assertSame(['b1'], array_map(static fn (TestNote $n): string => $n->text(), $this->em->getRepository(TestNote::class)->findAll()));
    }

    public function testSwitchingTenantRescopesWithoutManualSteps(): void
    {
        [$a, $b] = $this->seedTwoTenants();

        $this->context->switchTo($a);
        $first = $this->em->getRepository(TestNote::class)->findOneBy(['text' => 'a1']);
        self::assertInstanceOf(TestNote::class, $first);
        $id = $first->id();

        $this->context->switchTo($b);

        self::assertNull($this->em->find(TestNote::class, $id));
        self::assertSame(['b1'], array_map(static fn (TestNote $n): string => $n->text(), $this->em->getRepository(TestNote::class)->findAll()));
    }

    public function testSetRefusesToChangeAnActiveTenant(): void
    {
        $a = TenantId::generate();
        $this->context->set($a);
        $this->context->set(TenantId::fromString($a->toString()));

        $this->expectException(TenantAlreadySet::class);
        $this->context->set(TenantId::generate());
    }

    public function testResetClearsTenantAndFailsClosed(): void
    {
        $this->context->set(TenantId::generate());

        self::getContainer()->get('services_resetter')->reset();

        self::assertFalse($this->context->has());
        $this->expectException(NoActiveTenant::class);
        $this->context->current();
    }

    public function testJoinIsScopedToActiveTenant(): void
    {
        [$a] = $this->seedAttachments();
        $this->context->switchTo($a);

        $labels = $this->em->createQuery(
            'SELECT t.label FROM '.TestAttachment::class.' t JOIN t.note n ORDER BY t.label',
        )->getSingleColumnResult();
        self::assertSame(['a-att'], $labels);

        $notes = $this->em->createQuery(
            'SELECT n.text FROM '.TestNote::class.' n JOIN n.attachments t ORDER BY n.text',
        )->getSingleColumnResult();
        self::assertSame(['a1'], $notes);
    }

    public function testLazyCollectionIsScopedToActiveTenant(): void
    {
        [$a] = $this->seedAttachments();
        $this->context->switchTo($a);

        $note = $this->em->getRepository(TestNote::class)->findOneBy(['text' => 'a1']);
        self::assertInstanceOf(TestNote::class, $note);

        self::assertSame(['a-att'], array_map(static fn (TestAttachment $t): string => $t->label(), $note->attachments()));
    }

    public function testPersistWithoutTenantThrows(): void
    {
        $this->expectException(NoActiveTenant::class);

        $this->em->persist(new TestNote('orphan'));
        $this->em->flush();
    }

    public function testStampedTenantIsStoredOnRow(): void
    {
        $a = TenantId::generate();
        $this->context->set($a);
        $note = new TestNote('stamped');
        $this->em->persist($note);
        $this->em->flush();

        self::assertTrue($note->tenantId()->equals($a));

        $stored = $this->em->getConnection()->fetchOne('SELECT tenant_id FROM test_note');
        self::assertSame($a->toString(), $stored);
    }

    public function testPersistingAnotherTenantsEntityIsRejected(): void
    {
        $this->context->set(TenantId::generate());
        $note = new TestNote('mine');
        $this->em->persist($note);
        $this->em->flush();

        $this->context->switchTo(TenantId::generate());
        $this->expectException(TenantMismatch::class);
        $this->em->persist($note);
    }

    public function testCannotChangeTenantOfExistingRow(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            new \ReflectionClass(TestNote::class)->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        self::assertSame(['__construct', 'id', 'text', 'attachments', 'tenantId'], $methods);
    }

    public function testEveryTenantOwnedEntityIsMappedSafely(): void
    {
        $checked = 0;
        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $metadata) {
            if (!is_subclass_of($metadata->getName(), TenantOwned::class)) {
                continue;
            }
            ++$checked;
            $name = $metadata->getName();

            self::assertSame($name, $metadata->rootEntityName, $name.' must be a root entity');
            self::assertTrue($metadata->hasField('tenantId'), $name);
            $mapping = $metadata->getFieldMapping('tenantId');
            self::assertSame('tenant_id', $mapping->columnName, $name);
            self::assertSame('tenant_id', $mapping->type, $name);
            self::assertNotTrue($mapping->nullable, $name);

            $indexed = false;
            foreach ($metadata->table['indexes'] ?? [] as $index) {
                $columns = is_array($index) && is_array($index['columns'] ?? null) ? $index['columns'] : [];
                $indexed = $indexed || 'tenant_id' === ($columns[0] ?? null);
            }
            self::assertTrue($indexed, $name.' needs an index starting with tenant_id');
        }

        self::assertGreaterThanOrEqual(2, $checked);
    }

    /**
     * @return array{TenantId, TenantId}
     */
    private function seedTwoTenants(): array
    {
        $a = TenantId::generate();
        $b = TenantId::generate();

        foreach ([[$a, 'a1'], [$a, 'a2'], [$b, 'b1']] as [$tenant, $text]) {
            $this->context->switchTo($tenant);
            $this->em->persist(new TestNote($text));
            $this->em->flush();
        }

        return [$a, $b];
    }

    /**
     * Tenant A: note a1 with attachment a-att. Tenant B: note b1 with b-att,
     * plus an attachment of tenant B pointing at A's note (a cross-tenant FK,
     * which only raw SQL can still produce).
     *
     * @return array{TenantId, TenantId}
     */
    private function seedAttachments(): array
    {
        $a = TenantId::generate();
        $b = TenantId::generate();

        $this->context->switchTo($a);
        $noteA = new TestNote('a1');
        $this->em->persist($noteA);
        $this->em->persist(new TestAttachment($noteA, 'a-att'));
        $this->em->flush();
        $noteAId = $noteA->id();

        $this->context->switchTo($b);
        $noteB = new TestNote('b1');
        $this->em->persist($noteB);
        $this->em->persist(new TestAttachment($noteB, 'b-att'));
        $this->em->flush();

        // Written by SQL: the entity layer refuses such a reference (TenantReferenceTest).
        $this->em->getConnection()->insert('test_attachment', [
            'id' => Id::generate(),
            'tenant_id' => $b->toString(),
            'note_id' => $noteAId,
            'label' => 'b-on-a',
        ]);

        return [$a, $b];
    }
}
