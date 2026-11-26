<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Version\Version;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Shared\Domain\View\Placement;
use Foresi\Shared\Domain\View\SavedView;
use Foresi\Shared\Domain\View\SavedViewUserState;
use Foresi\Shared\Domain\View\Visibility;
use Foresi\Shared\Migrations\Version20261127090000;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A list keeps no density of its own: the key leaves the saved views and what a person's lists
 * remembered, and the rest of their displays stays as it was.
 */
final class ListDensityMigrationTest extends KernelTestCase
{
    private const string USER = '019a0000-0000-7000-8000-000000000001';

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

    public function testTheDensityLeavesTheViewsAndTheRememberedDisplays(): void
    {
        $columns = $this->view('Columns', ['columns' => [['key' => 'number', 'width' => 120]], 'density' => 'dense', 'group_by' => 'customer']);
        $alone = $this->view('Density alone', ['density' => 'comfortable']);
        $none = $this->view('No display', null);
        $kept = $this->view('Kept', ['columns' => [['key' => 'density']]]);
        $this->em->persist(new SavedViewUserState($columns, self::USER, ['columns' => [['key' => 'number']], 'density' => 'dense', 'pinned' => []], new \DateTimeImmutable('2026-11-20')));
        $this->em->persist(new SavedViewUserState($alone, self::USER, ['density' => 'dense'], new \DateTimeImmutable('2026-11-20')));
        $this->em->flush();

        $this->migration()->postUp(new Schema());

        // PostgreSQL keeps jsonb in an order of its own, so the keys are compared without it.
        self::assertEquals(['columns' => [['key' => 'number', 'width' => 120]], 'group_by' => 'customer'], $this->display('saved_view', 'id', $columns));
        self::assertNull($this->display('saved_view', 'id', $alone), 'A view that kept only a density goes back to the list\'s defaults.');
        self::assertNull($this->display('saved_view', 'id', $none));
        self::assertSame(['columns' => [['key' => 'density']]], $this->display('saved_view', 'id', $kept), 'A column of that name stays.');
        self::assertEquals(['columns' => [['key' => 'number']], 'pinned' => []], $this->display('saved_view_user_state', 'view_id', $columns));
        self::assertFalse($this->connection()->fetchOne('SELECT 1 FROM saved_view_user_state WHERE view_id = ?', [$alone]), 'A remembered display left empty is forgotten.');
    }

    /**
     * @param array<string, mixed>|null $display
     */
    private function view(string $name, ?array $display): string
    {
        $view = SavedView::made('invoicing.invoices', self::USER, $name, Visibility::Private, ['match' => 'all', 'conditions' => []], [], $display, false, Placement::Primary, 1, new \DateTimeImmutable('2026-11-20'));
        $this->em->persist($view);

        return $view->id();
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function display(string $table, string $key, string $id): ?array
    {
        $json = $this->connection()->fetchOne('SELECT display FROM '.$table.' WHERE '.$key.' = ?', [$id]);
        $display = \is_string($json) ? json_decode($json, true, flags: \JSON_THROW_ON_ERROR) : null;

        return \is_array($display) ? $display : null;
    }

    /**
     * Loaded by Doctrine from the module's migrations directory, as a deploy loads it.
     */
    private function migration(): AbstractMigration
    {
        /** @var DependencyFactory $factory */
        $factory = self::getContainer()->get('doctrine.migrations.dependency_factory');
        $class = $factory->getMigrationRepository()->getMigration(new Version(Version20261127090000::class))->getMigration()::class;

        return new $class($this->connection(), $factory->getLogger());
    }

    private function connection(): Connection
    {
        return $this->em->getConnection();
    }
}
