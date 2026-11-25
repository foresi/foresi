<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Infrastructure\Directory;

use Foresi\Tests\Work\Application\WorkTestCase;
use Foresi\Work\Application\ManageTime;
use Foresi\Work\Contract\ProjectDirectory;
use Foresi\Work\Contract\ProjectSummary;
use Foresi\Work\Contract\TimeTotals;

/**
 * The minutes on a project and the projects of a client, as other modules read
 * them through Work's contract: what the person sees, without the trash.
 */
final class TimeTotalsTest extends WorkTestCase
{
    public function testBillableOnlyFromADateWithoutTheTrashOrARunningTimer(): void
    {
        $time = self::getContainer()->get(ManageTime::class);
        $totals = self::getContainer()->get(TimeTotals::class);
        $project = $this->project();
        $other = $this->project();
        $task = $this->tasks->create($project->id(), 'Homepage');
        $time->log($project->id(), null, new \DateTimeImmutable('2026-10-30'), 30, billable: true);
        $time->log($project->id(), null, new \DateTimeImmutable('2026-11-02'), 45, billable: true);
        $time->log($project->id(), null, new \DateTimeImmutable('2026-11-03'), 20, billable: false);
        $time->log($other->id(), null, new \DateTimeImmutable('2026-11-03'), 500, billable: true);
        $time->delete($time->log($project->id(), null, new \DateTimeImmutable('2026-11-04'), 100, billable: true)->id());
        $time->log($project->id(), $task->id(), new \DateTimeImmutable('2026-11-05'), 200, billable: true);
        $this->tasks->delete($task->id());
        $time->startTimer($project->id(), null, billable: true);

        self::assertSame(95, $totals->minutes($project->id()));
        self::assertSame(75, $totals->minutes($project->id(), billableOnly: true));
        self::assertSame(65, $totals->minutes($project->id(), new \DateTimeImmutable('2026-11-01')));
        self::assertSame(45, $totals->minutes($project->id(), new \DateTimeImmutable('2026-11-01'), true));
        self::assertSame(0, $totals->minutes('not an id'));
    }

    public function testTheProjectsOfAClientByNameArchivedOnlyWhenAsked(): void
    {
        $directory = self::getContainer()->get(ProjectDirectory::class);
        $client = $this->em->getConnection()->fetchOne('SELECT id FROM party LIMIT 1');
        self::assertIsString($client);
        $zoo = $this->projects->create('Zoo', $client);
        $apps = $this->projects->create('Apps', $client);
        $this->projects->create('Internal', null);
        $this->projects->archive($apps->id());

        self::assertSame(['Zoo'], array_map(static fn (ProjectSummary $project): string => $project->name, $directory->ofClient($client)));
        self::assertSame(['Apps', 'Zoo'], array_map(static fn (ProjectSummary $project): string => $project->name, $directory->ofClient($client, false)));
        $found = $directory->find($zoo->id());
        self::assertNotNull($found);
        self::assertSame([$zoo->id(), 'Zoo', $client, false, '/projects/'.$zoo->id()], [$found->id, $found->name, $found->clientId, $found->archived, $found->url]);
        self::assertTrue($directory->find($apps->id())?->archived);
        self::assertNull($directory->find('not an id'));
    }
}
