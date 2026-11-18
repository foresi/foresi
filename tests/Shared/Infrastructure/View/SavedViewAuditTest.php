<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\View;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Audit\Domain\AuditAction;
use Foresi\Audit\Domain\AuditEntry;
use Foresi\Shared\Application\View\SavedViewStore;
use Foresi\Shared\Domain\View\Placement;
use Foresi\Shared\Domain\View\SavedView;
use Foresi\Shared\Domain\View\Visibility;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The shared kernel cannot mark its saved views Audited, so the audit listener
 * names them: every write of a view is in the audit log, as every other write.
 */
final class SavedViewAuditTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    public function testMakingChangingAndDeletingAViewIsAudited(): void
    {
        $store = self::getContainer()->get(SavedViewStore::class);
        $owner = '0b9a3c6e-3f7c-4a43-9a51-6f6f1d0a0001';
        $view = SavedView::made('invoicing.invoices', $owner, 'Due this week', Visibility::Shared, ['match' => 'all', 'conditions' => []], [], null, false, Placement::Primary, 9, new \DateTimeImmutable('2026-11-18'));
        $store->add($view);
        $store->flush();
        $view->edit($owner, new \DateTimeImmutable('2026-11-19'), name: 'Due soon');
        $store->flush();
        $id = $view->id();
        $store->remove($view);
        $store->flush();

        $actions = array_map(
            static fn (AuditEntry $entry): AuditAction => $entry->action(),
            $this->em->createQuery('SELECT e FROM '.AuditEntry::class.' e WHERE e.entityClass = :class AND e.entityId = :id ORDER BY e.occurredAt, e.id')
                ->setParameters(['class' => SavedView::class, 'id' => $id])
                ->getResult(),
        );
        self::assertSame([AuditAction::Created, AuditAction::Updated, AuditAction::Deleted], $actions);
    }
}
