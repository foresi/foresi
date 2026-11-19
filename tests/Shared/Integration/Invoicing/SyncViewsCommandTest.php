<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Integration\Invoicing;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Invoicing\Application\View\InvoiceLists;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Shared\Application\View\ListDefinitions;
use Foresi\Shared\Application\View\ListViews;
use Foresi\Shared\Domain\View\SavedView;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:views:sync stores the default views the modules declare, here the lists
 * of invoices, and keeps the ones a person edited or hid.
 */
final class SyncViewsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CommandTester $sync;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->sync = new CommandTester(new Application($kernel)->find('app:views:sync'));
    }

    public function testItStoresTheDefaultViewsOnceAndAgainChangesNothing(): void
    {
        $declared = array_sum(array_map(static fn ($list): int => \count($list->defaults), self::getContainer()->get(ListDefinitions::class)->all()));

        self::assertSame(0, $this->sync->execute([]));
        self::assertStringContainsString(\sprintf('%d added, 0 updated, 0 edited', $declared), $this->sync->getDisplay());
        self::assertSame($declared, $this->stored());
        $paid = $this->view('invoicing.invoices.paid');
        self::assertSame(['invoicing.invoices', 'invoice.list.tab.paid', null, true, 1], [$paid->listKey(), $paid->name(), $paid->ownerId(), $paid->showCount(), $paid->position()]);
        self::assertSame(['match' => 'all', 'conditions' => [['field' => 'payment', 'op' => 'is', 'value' => 'paid']]], $paid->filters());
        self::assertNull($paid->display());

        $this->em->clear();
        self::assertSame(0, $this->sync->execute([]));
        self::assertStringContainsString('0 added, 0 updated, 0 edited', $this->sync->getDisplay());
        self::assertSame($declared, $this->stored());
    }

    public function testItBringsAnUneditedViewToWhatTheModuleShipsAndLeavesEditedAndHiddenOnesAlone(): void
    {
        $this->sync->execute([]);
        $connection = $this->em->getConnection();
        // What an older release shipped, an edit and a hide, as the views would hold them.
        $connection->executeStatement("UPDATE saved_view SET name = 'Old name', position = 9 WHERE system_key = 'invoicing.invoices.paid'");
        $connection->executeStatement("UPDATE saved_view SET name = 'Our unpaid', updated_by = '01930000-0000-7000-8000-000000000001' WHERE system_key = 'invoicing.invoices.unpaid'");
        $connection->executeStatement("UPDATE saved_view SET position = 7, hidden = true WHERE system_key = 'invoicing.invoices.overdue'");
        $connection->executeStatement("DELETE FROM saved_view WHERE system_key = 'invoicing.invoices.trash'");
        $this->em->clear();

        $this->sync->execute([]);

        self::assertStringContainsString('1 added, 1 updated, 2 edited or hidden left alone', $this->sync->getDisplay());
        $this->em->clear();
        self::assertSame(['invoice.list.tab.paid', 1], [$this->view('invoicing.invoices.paid')->name(), $this->view('invoicing.invoices.paid')->position()]);
        self::assertSame('Our unpaid', $this->view('invoicing.invoices.unpaid')->name());
        self::assertSame([7, true], [$this->view('invoicing.invoices.overdue')->position(), $this->view('invoicing.invoices.overdue')->isHidden()]);
        self::assertSame('invoice.list.tab.trash', $this->view('invoicing.invoices.trash')->name());
    }

    public function testTheListShowsStoredViewsAsTheyAreAndDeclaredOnesUntilStored(): void
    {
        $views = self::getContainer()->get(ListViews::class);
        $list = InvoiceLists::of(DocumentKind::Invoice);
        $declared = array_map(static fn ($view): string => $view->key, $views->of($list, null));
        self::assertSame(['all', 'paid', 'unpaid', 'overdue', 'to_refund', 'trash'], $declared);

        $this->sync->execute([]);
        $connection = $this->em->getConnection();
        $connection->executeStatement("UPDATE saved_view SET name = 'Owed', updated_by = '01930000-0000-7000-8000-000000000001' WHERE system_key = 'invoicing.invoices.unpaid'");
        $connection->executeStatement("UPDATE saved_view SET hidden = true WHERE system_key = 'invoicing.invoices.to_refund'");
        $connection->executeStatement("UPDATE saved_view SET placement = 'overflow' WHERE system_key = 'invoicing.invoices.trash'");
        $this->em->clear();

        $shown = $views->of($list, null);
        self::assertSame(['all', 'paid', 'unpaid', 'overdue', 'trash'], array_map(static fn ($view): string => $view->key, $shown));
        self::assertSame(['Owed', false], [$shown[2]->name, $shown[2]->translated]);
        [$primary, $overflow] = ListViews::placed($shown);
        self::assertSame(['trash'], array_map(static fn (\Foresi\Shared\Application\View\ListView $view): string => $view->key, $overflow));
        self::assertCount(4, $primary);
    }

    private function view(string $systemKey): SavedView
    {
        return $this->em->getRepository(SavedView::class)->findOneBy(['systemKey' => $systemKey]) ?? throw new \LogicException('The view is stored.');
    }

    private function stored(): int
    {
        return \count($this->em->getRepository(SavedView::class)->findAll());
    }
}
