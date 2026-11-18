<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Events\Infrastructure\Relay;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Events\Infrastructure\Relay\EventListeners;
use Foresi\Events\Infrastructure\Relay\EventRelay;
use Foresi\Events\Infrastructure\Relay\PruneEventLogTask;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Events\Support\EventRows;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;

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
        $old = Id::generate();
        $recent = Id::generate();
        $unpositioned = Id::generate();
        $highest = Id::generate();
        EventRows::insert($this->db, $old, '2026-10-12 09:59:59', 1);
        EventRows::insert($this->db, $recent, '2026-10-12 10:00:01', 2);
        EventRows::insert($this->db, $highest, '2026-11-11 09:00:00', 3);
        EventRows::insert($this->db, $unpositioned, '2026-09-01 10:00:00');
        foreach ([$old, $recent] as $id) {
            $this->db->insert('event_consumption', ['listener' => 'test.listener', 'event_id' => $id, 'consumed_at' => '2026-10-12 10:00:00']);
        }

        new PruneEventLogTask($this->db, new MockClock('2026-11-11 10:00:00', 'UTC'))->run();

        self::assertSame([$recent => 2, $unpositioned => null, $highest => 3], EventRows::positions($this->db));
        self::assertSame([$recent], $this->db->fetchFirstColumn('SELECT event_id FROM event_consumption'));
    }

    public function testKeepsTheHighestPositionSoTheNextEventNumbersOn(): void
    {
        $older = Id::generate();
        $newest = Id::generate();
        EventRows::insert($this->db, $older, '2026-09-01 10:00:00', 41);
        EventRows::insert($this->db, $newest, '2026-09-02 10:00:00', 42);
        foreach ([$older, $newest] as $id) {
            $this->db->insert('event_consumption', ['listener' => 'test.listener', 'event_id' => $id, 'consumed_at' => '2026-09-02 10:00:00']);
        }

        new PruneEventLogTask($this->db, new MockClock('2026-11-11 10:00:00', 'UTC'))->run();
        $next = Id::generate();
        EventRows::insert($this->db, $next, '2026-11-11 10:00:00');
        $container = self::getContainer();
        new EventRelay($this->db, $container->get(LockFactory::class), new EventListeners(new ServiceLocator([])), $container->get(MessageBusInterface::class))->relay();

        self::assertSame([$newest => 42, $next => 43], EventRows::positions($this->db));
        self::assertSame([$newest], $this->db->fetchFirstColumn('SELECT event_id FROM event_consumption'));
    }

    public function testPrunesNothingInAnEmptyLog(): void
    {
        new PruneEventLogTask($this->db, new MockClock('2026-11-11 10:00:00', 'UTC'))->run();

        self::assertSame([], EventRows::positions($this->db));
    }

    public function testRunsDaily(): void
    {
        $task = new PruneEventLogTask($this->db, new MockClock());

        self::assertSame('event-log-prune', $task->name());
        self::assertSame('1 day', $task->every());
    }
}
