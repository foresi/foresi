<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Application;

use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Work\Application\WorkUnit;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class WorkUnitTest extends WorkTestCase
{
    private WorkUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->unit = self::getContainer()->get(WorkUnit::class);
    }

    public function testChangesAreOneTransaction(): void
    {
        $before = $this->parties();

        try {
            $this->unit->run(function (): void {
                $this->em->persist(new Party(PartyKind::Person, 'Stored too early', null, null, ''));
                $this->em->flush();

                throw new \RuntimeException('Refused after the first flush.');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame($before, $this->parties());
    }

    public function testAUnitThatSucceedsStoresItsChanges(): void
    {
        $before = $this->parties();

        $this->unit->run(fn () => $this->em->persist(new Party(PartyKind::Person, 'Stored', null, null, '')));

        self::assertSame($before + 1, $this->parties());
    }

    /**
     * Mail is handed to the queue while the unit runs, so a unit that fails
     * before it reaches the notification queues none; the control shows the
     * transport would have seen it.
     */
    public function testFailedUnitQueuesNoMail(): void
    {
        $task = $this->tasks->create($this->project()->id(), 'Fix the contact form');
        $queue = $this->queue();
        $queue->reset();

        try {
            $this->unit->run(function (): void {
                $this->em->persist(new Party(PartyKind::Person, 'Never stored', null, null, ''));

                throw new \RuntimeException('Refused.');
            });
        } catch (\RuntimeException) {
        }
        self::assertCount(0, $queue->getSent());

        $this->tasks->assign($task->id(), $this->member->id());
        self::assertCount(1, $queue->getSent());
    }

    private function queue(): InMemoryTransport
    {
        $queue = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $queue);

        return $queue;
    }

    private function parties(): int
    {
        $count = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM party');

        return \is_numeric($count) ? (int) $count : 0;
    }
}
