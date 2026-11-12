<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Events\Infrastructure\Relay;

use App\Events\Infrastructure\Relay\PruneEventLogTask;
use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use App\Tests\Events\Support\EventRows;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The log keeps thirty days of relayed events. A row the relay has not numbered
 * yet is never deleted, however old, or its listeners would never see it.
 */
final class PruneEventLogTaskTest extends KernelTestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->db = $em->getConnection();
    }

    public function testKeepsThirtyDays(): void
    {
        $tenant = TenantId::generate()->toString();
        $old = Id::generate();
        $recent = Id::generate();
        $unpositioned = Id::generate();
        EventRows::insert($this->db, $old, $tenant, '2026-10-12 09:59:59', 1);
        EventRows::insert($this->db, $recent, $tenant, '2026-10-12 10:00:01', 2);
        EventRows::insert($this->db, $unpositioned, $tenant, '2026-09-01 10:00:00');
        foreach ([$old, $recent] as $id) {
            $this->db->insert('event_consumption', ['listener' => 'test.listener', 'event_id' => $id, 'consumed_at' => '2026-10-12 10:00:00']);
        }

        new PruneEventLogTask($this->db, new MockClock('2026-11-11 10:00:00', 'UTC'))->run();

        self::assertSame([$recent => 2, $unpositioned => null], EventRows::positions($this->db));
        self::assertSame([$recent], $this->db->fetchFirstColumn('SELECT event_id FROM event_consumption'));
    }

    public function testRunsDaily(): void
    {
        $task = new PruneEventLogTask($this->db, new MockClock());

        self::assertSame('event-log-prune', $task->name());
        self::assertSame('1 day', $task->every());
    }
}
