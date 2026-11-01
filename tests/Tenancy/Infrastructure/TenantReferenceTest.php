<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Tenancy\Infrastructure;

use App\Tenancy\Application\TenantContext;
use App\Tenancy\Application\TenantMismatch;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Infrastructure\Doctrine\TenantFilter;
use App\Tests\Tenancy\Fixtures\TestAttachment;
use App\Tests\Tenancy\Fixtures\TestNote;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TenantReferenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TenantContext $context;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->context = $container->get(TenantContext::class);

        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    public function testAReferenceToAnotherTenantIsRefused(): void
    {
        $noteAId = $this->seedNote(TenantId::generate(), 'a1')->id();

        $this->context->switchTo(TenantId::generate());
        $this->em->persist(new TestAttachment($this->loadAcrossTenants($noteAId), 'b-on-a'));

        $this->expectException(TenantMismatch::class);
        $this->em->flush();
    }

    public function testAReferenceToAnotherTenantIsRefusedOnUpdate(): void
    {
        $noteAId = $this->seedNote(TenantId::generate(), 'a1')->id();
        $this->context->switchTo(TenantId::generate());
        $noteB = new TestNote('b1');
        $attachment = new TestAttachment($noteB, 'b-att');
        $this->em->persist($noteB);
        $this->em->persist($attachment);
        $this->em->flush();

        $noteA = $this->loadAcrossTenants($noteAId);
        new \ReflectionProperty(TestAttachment::class, 'note')->setValue($attachment, $noteA);

        $this->expectException(TenantMismatch::class);
        $this->em->flush();
    }

    public function testAnUninitializedProxyOfAnotherTenantIsRefused(): void
    {
        $noteA = $this->seedNote(TenantId::generate(), 'a1');
        $noteAId = $noteA->id();
        $this->em->clear();

        $this->context->switchTo(TenantId::generate());
        $this->em->persist(new TestAttachment($this->em->getReference(TestNote::class, $noteAId) ?? throw new \LogicException('Missing.'), 'b-on-a'));

        $this->expectException(TenantMismatch::class);
        $this->em->flush();
    }

    public function testAnUninitializedProxyOfAnotherTenantIsRefusedOnUpdate(): void
    {
        $noteA = $this->seedNote(TenantId::generate(), 'a1');
        $noteAId = $noteA->id();
        $this->em->clear();

        $this->context->switchTo(TenantId::generate());
        $noteB = new TestNote('b1');
        $attachment = new TestAttachment($noteB, 'b-att');
        $this->em->persist($noteB);
        $this->em->persist($attachment);
        $this->em->flush();
        new \ReflectionProperty(TestAttachment::class, 'note')->setValue($attachment, $this->em->getReference(TestNote::class, $noteAId));

        $this->expectException(TenantMismatch::class);
        $this->em->flush();
    }

    public function testAnUpdateLeavingAReferenceAloneDoesNotLoadIt(): void
    {
        $this->context->switchTo(TenantId::generate());
        $note = new TestNote('a1');
        $attachment = new TestAttachment($note, 'a-att');
        $this->em->persist($note);
        $this->em->persist($attachment);
        $this->em->flush();
        $this->em->clear();

        $stored = $this->em->find(TestAttachment::class, $attachment->id()) ?? throw new \LogicException('The attachment is missing.');
        $stored->relabel('renamed');
        self::assertTrue($this->em->getUnitOfWork()->isUninitializedObject($stored->note()));

        $this->em->flush();

        self::assertTrue($this->em->getUnitOfWork()->isUninitializedObject($stored->note()));
        self::assertSame('renamed', $this->em->getConnection()->fetchOne('SELECT label FROM test_attachment'));
    }

    public function testAReferenceWithinOneTenantIsAccepted(): void
    {
        $this->context->switchTo(TenantId::generate());
        $note = new TestNote('a1');
        $this->em->persist($note);
        $this->em->flush();

        $this->em->persist(new TestAttachment($note, 'a-att'));
        $this->em->flush();

        self::assertEquals(1, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM test_attachment'));
    }

    public function testAParentAndChildPersistedInOneFlushAreAccepted(): void
    {
        $this->context->switchTo(TenantId::generate());
        $note = new TestNote('a1');
        $attachment = new TestAttachment($note, 'a-att');
        $this->em->persist($attachment);
        $this->em->persist($note);
        $this->em->flush();

        self::assertTrue($attachment->tenantId()->equals($note->tenantId()));
    }

    private function loadAcrossTenants(string $id): TestNote
    {
        $filters = $this->em->getFilters();
        $filters->suspend(TenantFilter::NAME);
        try {
            $note = $this->em->find(TestNote::class, $id);
        } finally {
            $filters->restore(TenantFilter::NAME);
        }

        return $note ?? throw new \LogicException('The note is missing.');
    }

    private function seedNote(TenantId $tenant, string $text): TestNote
    {
        $this->context->switchTo($tenant);
        $note = new TestNote($text);
        $this->em->persist($note);
        $this->em->flush();

        return $note;
    }
}
