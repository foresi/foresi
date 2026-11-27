<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Timesheet\Application;

use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Tests\Work\Application\WorkTestCase;
use Foresi\Timesheet\Application\ManageTime;
use Foresi\Timesheet\Application\TimeGrouping;
use Foresi\Timesheet\Application\TimeQueries;
use Foresi\Timesheet\Application\TimeReportFilter;
use Foresi\Work\Domain\Project;

/**
 * The search of the week and the report: the text finds time by its note, its
 * task, its project or the project's client, and a wildcard of LIKE is no
 * wildcard of the search.
 */
final class TimeSearchTest extends WorkTestCase
{
    private Project $website;
    private Project $audit;

    protected function setUp(): void
    {
        parent::setUp();
        $client = new Party(PartyKind::Person, 'Beta Example', null, null, '');
        $this->em->persist($client);
        $this->em->flush();
        $this->website = $this->projects->create('Website', null);
        $this->audit = $this->projects->create('Annual audit', $client->id());
    }

    public function testTimeIsFoundByItsNoteTaskProjectOrClient(): void
    {
        $time = self::getContainer()->get(ManageTime::class);
        $queries = self::getContainer()->get(TimeQueries::class);
        $brief = $this->tasks->create($this->website->id(), 'Write the brief');
        $day = new \DateTimeImmutable('2026-11-09');
        $time->log($this->website->id(), $brief->id(), $day, 60, note: 'Drafted the outline');
        $time->log($this->audit->id(), null, $day, 30, note: 'Counted');

        $minutes = static function (string $text) use ($queries, $day): int {
            $week = $queries->week($day, null, $text);
            self::assertNotNull($week);

            return $week->totalMinutes;
        };
        self::assertSame(90, $minutes(''));
        self::assertSame(60, $minutes('OUTLINE'), 'by the note');
        self::assertSame(60, $minutes('brief'), 'by the task');
        self::assertSame(30, $minutes('annual audit'), 'by the project');
        self::assertSame(30, $minutes('beta example'), 'by the client');
        self::assertSame(0, $minutes('%'));

        $report = static fn (string $text): array => [$queries->report(TimeReportFilter::ofView(new ViewFilter(), $text), TimeGrouping::Project)->totalMinutes, $queries->report(TimeReportFilter::ofView(new ViewFilter(), $text), TimeGrouping::Task)->entryCount];
        self::assertSame([90, 2], $report(''));
        self::assertSame([60, 1], $report('outline'));
        self::assertSame([30, 1], $report('Beta'));
        self::assertSame([0, 0], $report('%'));
    }
}
