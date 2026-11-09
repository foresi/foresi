<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Application;

use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Shared\Domain\Id;
use App\Work\Application\WorkScope;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\ProjectVisibility;
use App\Work\Domain\Section;
use App\Work\Domain\StatusType;
use App\Work\Domain\Task;
use App\Work\Domain\WorkflowTemplate;
use App\Work\Domain\WorkShare;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rule of who sees what, as a table: one organisation's work, and what
 * each person sees of it by their role and what is shared with them. Sharing
 * opens no more than the rows say; the integration tests check that the
 * queries agree.
 */
final class WorkScopeTest extends TestCase
{
    /** The people, in the order of the columns of the table. */
    private const array PEOPLE = ['owner', 'member', 'member chosen', 'member section', 'member tasks', 'guest', 'guest project', 'guest section', 'guest task', 'nobody'];

    /**
     * What each sees, by the columns of PEOPLE: projects and sections as seen,
     * tasks as seen, in the project "Website" open to the organisation unless
     * the name says otherwise.
     */
    private const array SEES = [
        'project Website' => [1, 1, 1, 1, 1, 0, 1, 1, 0, 0],
        'project Chosen' => [1, 0, 1, 0, 0, 0, 0, 0, 0, 0],
        'section Design' => [1, 1, 1, 1, 1, 0, 1, 1, 0, 0],
        'section Secret' => [1, 0, 0, 1, 0, 0, 0, 0, 0, 0],
        'task without a section' => [1, 1, 1, 1, 1, 0, 1, 0, 1, 0],
        'task in Design' => [1, 1, 1, 1, 1, 0, 1, 1, 0, 0],
        'private task' => [1, 0, 0, 0, 1, 0, 0, 0, 0, 0],
        'private task in Design' => [1, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        'task in Secret' => [1, 0, 0, 1, 0, 0, 0, 0, 0, 0],
        'task in Secret shared alone' => [1, 0, 0, 1, 1, 0, 0, 0, 0, 0],
        'private task in Secret' => [1, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        'subtask of the task without a section' => [1, 1, 1, 1, 1, 0, 1, 0, 1, 0],
        'private subtask of the task without a section' => [1, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        'subtask of the private task' => [1, 0, 0, 0, 1, 0, 0, 0, 0, 0],
        'subtask in Secret of the task without a section' => [1, 1, 1, 1, 1, 0, 1, 0, 1, 0],
        'task in Chosen' => [1, 0, 1, 0, 0, 0, 0, 0, 0, 0],
    ];

    /** @var array<string, Project|Section|Task> */
    private array $work = [];

    /** @var array<string, WorkScope> */
    private array $scopes = [];

    protected function setUp(): void
    {
        $website = $this->project('Website', ProjectVisibility::Organisation);
        $chosen = $this->project('Chosen', ProjectVisibility::Chosen);
        $design = new Section($website, 'Design', 0);
        $secret = new Section($website, 'Secret', 1);
        $secret->makePrivate(true);
        $this->work['section Design'] = $design;
        $this->work['section Secret'] = $secret;

        $plain = $this->task('task without a section', $website);
        $this->task('task in Design', $website, $design);
        $private = $this->task('private task', $website, private: true);
        $this->task('private task in Design', $website, $design, private: true);
        $this->task('task in Secret', $website, $secret);
        $sharedAlone = $this->task('task in Secret shared alone', $website, $secret);
        $this->task('private task in Secret', $website, $secret, private: true);
        $this->task('subtask of the task without a section', $website, parent: $plain);
        $this->task('private subtask of the task without a section', $website, parent: $plain, private: true);
        $this->task('subtask of the private task', $website, parent: $private);
        $this->task('subtask in Secret of the task without a section', $website, $secret, $plain);
        $this->task('task in Chosen', $chosen);

        $this->scopes = [
            'owner' => WorkScope::everything(),
            'member' => WorkScope::shared(true, []),
            'member chosen' => WorkScope::shared(true, $this->shares($chosen)),
            'member section' => WorkScope::shared(true, $this->shares($secret)),
            'member tasks' => WorkScope::shared(true, $this->shares($private, $sharedAlone)),
            'guest' => WorkScope::shared(false, []),
            'guest project' => WorkScope::shared(false, $this->shares($website)),
            'guest section' => WorkScope::shared(false, $this->shares($design)),
            'guest task' => WorkScope::shared(false, $this->shares($plain)),
            'nobody' => WorkScope::nothing(),
        ];
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function rule(): iterable
    {
        foreach (self::SEES as $item => $row) {
            foreach (self::PEOPLE as $column => $person) {
                yield $person.' / '.$item => [$person, $item, 1 === $row[$column]];
            }
        }
    }

    #[DataProvider('rule')]
    public function testWhoSeesWhat(string $person, string $item, bool $sees): void
    {
        $scope = $this->scopes[$person];
        $work = $this->work[$item];

        self::assertSame($sees, match (true) {
            $work instanceof Project => $scope->seesProject($work),
            $work instanceof Section => $scope->seesSection($work),
            $work instanceof Task => $scope->seesTask($work),
        });
    }

    public function testAGuestOfASingleTaskKnowsTheNameOfItsProjectAlone(): void
    {
        $website = $this->work['project Website'];
        $chosen = $this->work['project Chosen'];
        self::assertInstanceOf(Project::class, $website);
        self::assertInstanceOf(Project::class, $chosen);

        self::assertFalse($this->scopes['guest task']->seesProject($website));
        self::assertTrue($this->scopes['guest task']->knowsProject($website));
        self::assertFalse($this->scopes['guest task']->knowsProject($chosen));
        self::assertTrue($this->scopes['guest section']->knowsProject($website));
        self::assertFalse($this->scopes['guest']->knowsProject($website));
    }

    public function testANewTaskGoesWhereThePersonSeesTheSectionOrTheWholeProject(): void
    {
        $website = $this->work['project Website'];
        $chosen = $this->work['project Chosen'];
        $design = $this->work['section Design'];
        $secret = $this->work['section Secret'];
        self::assertInstanceOf(Project::class, $website);
        self::assertInstanceOf(Project::class, $chosen);
        self::assertInstanceOf(Section::class, $design);
        self::assertInstanceOf(Section::class, $secret);

        self::assertTrue($this->scopes['guest section']->allowsTaskIn($website, $design));
        self::assertFalse($this->scopes['guest section']->allowsTaskIn($website, null));
        self::assertTrue($this->scopes['guest project']->allowsTaskIn($website, null));
        self::assertFalse($this->scopes['guest project']->allowsTaskIn($website, $secret));
        self::assertFalse($this->scopes['guest task']->allowsTaskIn($website, null));
        self::assertTrue($this->scopes['member section']->allowsTaskIn($website, $secret));
        self::assertFalse($this->scopes['member']->allowsTaskIn($website, $secret));
        self::assertFalse($this->scopes['member chosen']->allowsTaskIn($chosen, $design));
        self::assertTrue($this->scopes['owner']->allowsTaskIn($chosen, null));
    }

    public function testTheScopeCarriesTheSharedIdsForTheQueries(): void
    {
        $guest = $this->scopes['guest section'];

        self::assertFalse($guest->isEverything());
        self::assertFalse($guest->seesOrganisation());
        self::assertSame([$this->work['section Design']->id()], $guest->sectionIds());
        self::assertSame([$this->work['project Website']->id()], $guest->sectionProjectIds());
        self::assertSame([], [...$guest->projectIds(), ...$guest->taskIds(), ...$guest->taskProjectIds()]);
        self::assertTrue($this->scopes['member']->seesOrganisation());
        self::assertSame([$this->work['private task']->id(), $this->work['task in Secret shared alone']->id()], $this->scopes['member tasks']->taskIds());
    }

    private function project(string $name, ProjectVisibility $visibility): Project
    {
        $project = new Project($name, null, WorkflowTemplate::Simple, Id::generate(), new \DateTimeImmutable('2026-11-09 08:00'));
        $project->changeVisibility($visibility);
        $this->work['project '.$name] = $project;

        return $project;
    }

    private function task(string $title, Project $project, ?Section $section = null, ?Task $parent = null, bool $private = false): Task
    {
        $task = new Task($project, $title, new ProjectStatus($project, 'To do', StatusType::Open, 0), 0, Id::generate(), new \DateTimeImmutable('2026-11-09 08:00'), $section, $parent);
        $task->makePrivate($private);
        $this->work[$title] = $task;

        return $task;
    }

    /**
     * @return list<WorkShare>
     */
    private function shares(Project|Section|Task ...$items): array
    {
        $membership = new Membership(new User('person@example.com', 'hash', 'Person'), Role::Member);

        return array_values(array_map(static fn (Project|Section|Task $item): WorkShare => WorkShare::of($item, $membership, new \DateTimeImmutable('2026-11-09 09:00')), $items));
    }
}
