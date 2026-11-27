<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Application;

use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Work\Application\ManageComments;
use Foresi\Work\Application\NotificationQueries;
use Foresi\Work\Application\NotificationRow;
use Foresi\Work\Application\ProjectList;
use Foresi\Work\Application\ProjectRow;
use Foresi\Work\Application\TaskFilter;
use Foresi\Work\Application\TaskRow;
use Foresi\Work\Domain\Project;

/**
 * The text search of the work lists: what it looks in, whatever the case, and that
 * a wildcard of LIKE is no wildcard of the search.
 */
final class WorkSearchTest extends WorkTestCase
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

    public function testMyTasksAreFoundByTheirTitleOrTheirProjectName(): void
    {
        $this->tasks->create($this->website->id(), 'Write the brief');
        $this->tasks->create($this->audit->id(), 'Count the stock');

        $found = fn (string $text): array => array_map(static fn (TaskRow $task): string => $task->title, $this->queries->myTaskList(new ViewFilter(), $text));
        self::assertEqualsCanonicalizing(['Write the brief', 'Count the stock'], $found(''));
        self::assertSame(['Write the brief'], $found('BRIEF'));
        self::assertSame(['Count the stock'], $found('annual'), 'by the name of the project');
        self::assertSame([], $found('%'));
        self::assertSame([], $found('_'));
        self::assertSame(['Write the brief'], $found('  brief  '));
    }

    public function testProjectsAreFoundByTheirNameOrTheirClientsName(): void
    {
        $found = fn (string $text): array => array_map(static fn (ProjectRow $project): string => $project->name, $this->queries->projectIndex(new ViewFilter(), false, $text));

        self::assertSame(['Annual audit', 'Website'], $found(''));
        self::assertSame(['Website'], $found('WEB'));
        self::assertSame(['Annual audit'], $found('beta example'), 'by the name of the client');
        self::assertSame([], $found('%'));
    }

    public function testTheTasksOfAProjectAreFoundByTheirTitleOrTheTitleOfASubtask(): void
    {
        $brief = $this->tasks->create($this->website->id(), 'Write the brief');
        $this->tasks->create($this->website->id(), 'Order the hosting', parentId: $brief->id());
        $this->tasks->create($this->website->id(), 'Design the logo');

        $titles = fn (string $text): array => array_map(static fn (TaskRow $task): string => $task->title, array_merge(...array_map(static fn (\Foresi\Work\Application\SectionTasks $section): array => $section->tasks, $this->listOf(new TaskFilter(text: $text))->sections)));
        self::assertEqualsCanonicalizing(['Write the brief', 'Design the logo'], $titles(''));
        self::assertSame(['Design the logo'], $titles('LOGO'));
        self::assertSame(['Write the brief'], $titles('hosting'), 'the task whose subtask holds the text');
        self::assertSame([], $titles('%'));

        $board = $this->queries->board($this->website->id(), new TaskFilter(text: 'logo'));
        self::assertNotNull($board);
        $columns = $board->columns;
        self::assertSame(['Design the logo'], array_merge(...array_map(static fn (\Foresi\Work\Application\StatusTasks $column): array => array_map(static fn (TaskRow $task): string => $task->title, $column->tasks), $columns)));
    }

    public function testTheInboxIsFoundByTheTaskTheProjectOrTheActor(): void
    {
        $task = $this->tasks->create($this->website->id(), 'Write the brief');
        $this->signIn($this->member);
        self::getContainer()->get(ManageComments::class)->add($task->id(), 'Started on it');
        $this->signIn($this->owner);
        $queries = self::getContainer()->get(NotificationQueries::class);

        $found = fn (string $text): array => array_map(static fn (NotificationRow $row): string => $row->taskTitle, $queries->inbox($this->owner, new ViewFilter(), 1, $text)->rows);
        self::assertSame(['Write the brief'], $found(''));
        self::assertSame(['Write the brief'], $found('BRIEF'));
        self::assertSame(['Write the brief'], $found('website'));
        self::assertSame(['Write the brief'], $found('marek'), 'by whoever did it');
        self::assertSame([], $found('audit'));
        self::assertSame([], $found('%'));
    }

    private function listOf(TaskFilter $filter): ProjectList
    {
        $list = $this->queries->projectList($this->website->id(), $filter);
        self::assertNotNull($list);

        return $list;
    }
}
