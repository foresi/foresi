<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Application;

use App\Attachment\Application\AttachmentPermissions;
use App\Attachment\Domain\Attachment;
use App\Shared\Domain\Id;
use App\Work\Application\Choice;
use App\Work\Application\Comments;
use App\Work\Application\DiscussionFilter;
use App\Work\Application\DiscussionItem;
use App\Work\Application\ManageComments;
use App\Work\Application\ManageSharing;
use App\Work\Application\MentionCandidate;
use App\Work\Application\ProjectRow;
use App\Work\Application\TaskRow;
use App\Work\Application\TrashedTask;
use App\Work\Application\WorkRefused;
use App\Work\Application\WorkVisibility;
use App\Work\Domain\CommentMention;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectVisibility;
use App\Work\Domain\Section;
use App\Work\Domain\Task;
use App\Work\Domain\WorkflowTemplate;
use App\Work\Infrastructure\Doctrine\ScopedDql;

/**
 * The rule of WorkScope as the queries and commands apply it, on the
 * database: an open project with a private section and private tasks, and a
 * project for chosen people.
 */
final class SharedWorkTest extends WorkTestCase
{
    private ManageSharing $sharing;
    private ManageComments $comments;
    private WorkVisibility $visibility;
    private Project $website;
    private Project $chosen;
    private Section $design;
    private Section $secret;
    private Task $brief;
    private Task $logo;
    private Task $private;
    private Task $hidden;
    private Task $subtask;
    private Task $offer;

    protected function setUp(): void
    {
        parent::setUp();
        $container = self::getContainer();
        $this->sharing = $container->get(ManageSharing::class);
        $this->comments = $container->get(ManageComments::class);
        $this->visibility = $container->get(WorkVisibility::class);

        $this->website = $this->project();
        $this->design = $this->sections->add($this->website->id(), 'Design');
        $this->secret = $this->sections->add($this->website->id(), 'Secret');
        $this->brief = $this->tasks->create($this->website->id(), 'Write the brief');
        $this->subtask = $this->tasks->create($this->website->id(), 'Find a font', parentId: $this->brief->id());
        $this->logo = $this->tasks->create($this->website->id(), 'Draw the logo', sectionId: $this->design->id());
        $this->private = $this->tasks->create($this->website->id(), 'Agree the price', responsibleId: $this->member->id());
        $this->hidden = $this->tasks->create($this->website->id(), 'Hire a designer', sectionId: $this->secret->id());
        $this->sharing->makeTaskPrivate($this->private->id(), true);
        $this->sharing->makeSectionPrivate($this->secret->id(), true);
        $this->chosen = $this->projects->create('Offer', null, WorkflowTemplate::Simple);
        $this->offer = $this->tasks->create($this->chosen->id(), 'Send the offer');
        $this->sharing->changeVisibility($this->chosen->id(), ProjectVisibility::Chosen);
    }

    public function testTheQueriesAgreeWithTheRuleForEveryone(): void
    {
        $everyone = [$this->owner, $this->member, $this->guest];
        $cases = [
            'nothing shared' => [],
            'the chosen project' => [$this->chosen],
            'the private section' => [$this->secret],
            'a private task and a subtask' => [$this->private, $this->subtask],
            'the open project' => [$this->website],
            'the open section' => [$this->design],
        ];
        $all = $this->em->getRepository(Task::class)->findAll();
        foreach ($cases as $case => $items) {
            $this->signIn($this->owner);
            foreach ($items as $item) {
                foreach ([$this->member, $this->guest] as $person) {
                    match (true) {
                        $item instanceof Project => $this->sharing->shareProject($item->id(), $person->id()),
                        $item instanceof Section => $this->sharing->shareSection($item->id(), $person->id()),
                        $item instanceof Task => $this->sharing->shareTask($item->id(), $person->id()),
                    };
                }
            }
            foreach ($everyone as $person) {
                $this->signIn($person);
                $scope = $this->visibility->scope();
                $seen = ScopedDql::tasks($scope, 't');
                /** @var list<string> $found */
                $found = $seen->apply($this->em->createQuery('SELECT t.id FROM '.Task::class.' t'.$seen->joins.' WHERE 1 = 1'.$seen->where.' ORDER BY t.id'))->getSingleColumnResult();
                $expected = array_map(static fn (Task $task): string => $task->id(), array_values(array_filter($all, $scope->seesTask(...))));
                sort($expected);
                self::assertSame($expected, $found, $case.', '.$person->user()->displayName());

                $projects = ScopedDql::projects($scope, 'p');
                $found = $projects->apply($this->em->createQuery('SELECT p.id FROM '.Project::class.' p WHERE 1 = 1'.$projects->where.' ORDER BY p.id'))->getSingleColumnResult();
                $expected = array_map(static fn (Project $project): string => $project->id(), array_values(array_filter([$this->website, $this->chosen], $scope->seesProject(...))));
                sort($expected);
                self::assertSame($expected, $found, $case.', projects, '.$person->user()->displayName());
            }
            $this->signIn($this->owner);
            foreach ($items as $item) {
                foreach ([$this->member, $this->guest] as $person) {
                    match (true) {
                        $item instanceof Project => $this->sharing->unshareProject($item->id(), $person->id()),
                        $item instanceof Section => $this->sharing->unshareSection($item->id(), $person->id()),
                        $item instanceof Task => $this->sharing->unshareTask($item->id(), $person->id()),
                    };
                }
            }
        }
    }

    public function testAMemberSeesTheOpenProjectWithoutWhatIsPrivate(): void
    {
        $this->signIn($this->member);

        self::assertSame([['Website', 3]], array_map(static fn (ProjectRow $row): array => [$row->name, $row->openTasks], $this->queries->projects()));
        self::assertSame(['' => ['Write the brief'], 'Design' => ['Draw the logo']], $this->list($this->website));
        self::assertNull($this->queries->projectList($this->chosen->id()));
        self::assertNull($this->queries->task($this->private->id()));
        self::assertNull($this->queries->task($this->hidden->id()));
        // Responsible before it went private, and still not shown it.
        self::assertSame([], $this->queries->myTasks()[0]->tasks ?? []);
        self::assertSame(0, $this->queries->myDueCount());
        self::assertSame(['Design'], array_map(static fn (Choice $choice): string => $choice->name, $this->queries->projectSettings($this->website->id())->sections ?? []));
        self::assertSame(['Design'], array_map(static fn (Choice $choice): string => $choice->name, $this->queries->task($this->brief->id())->sections ?? []));

        $this->signIn($this->owner);
        $this->sharing->shareSection($this->secret->id(), $this->member->id());
        $this->signIn($this->member);
        self::assertSame(['' => ['Write the brief'], 'Design' => ['Draw the logo'], 'Secret' => ['Hire a designer']], $this->list($this->website));
    }

    public function testAGuestWithASharedProjectSeesItsOpenWorkAndNoInternalComment(): void
    {
        $internal = $this->comments->add($this->brief->id(), 'Our margin is thin');
        $public = $this->comments->add($this->brief->id(), 'Hello', false);
        $file = new Attachment(Id::generate(), 'task_comment', $internal->id(), 'margin.pdf', 'application/pdf', 10, str_repeat('a', 64), 'key', $this->owner->user()->id(), $this->clock->now());
        $this->em->persist($file);
        $this->em->flush();
        $this->sharing->shareProject($this->website->id(), $this->guest->id());

        $this->signIn($this->guest);
        self::assertSame(['Website'], array_map(static fn (ProjectRow $row): string => $row->name, $this->queries->projects()));
        self::assertSame(['' => ['Write the brief'], 'Design' => ['Draw the logo']], $this->list($this->website));
        self::assertSame([$public->id()], array_map(static fn (DiscussionItem $item): ?string => $item->comment?->id, $this->queries->discussion($this->brief->id(), DiscussionFilter::Comments) ?? []));
        $permissions = self::getContainer()->get(AttachmentPermissions::class);
        self::assertFalse($permissions->canSee('task_comment', $internal->id()));
        self::assertTrue($permissions->canSee('task', $this->brief->id()));
        $this->assertRefused('unknown', fn () => $this->comments->delete($internal->id()));
        self::assertNull($this->queries->projectSettings($this->website->id()));
        self::assertNull($this->queries->trash($this->website->id()));
        self::assertSame([], $this->queries->labels());

        // A guest comments in the open, whatever they ask for, and creates tasks.
        $comment = $this->comments->add($this->brief->id(), 'Looks good');
        self::assertFalse($comment->isInternal());
        $task = $this->tasks->create($this->website->id(), 'Send the photos');
        self::assertSame($this->website->id(), $task->project()->id());
        $this->assertRefused('task_not_yours', fn (): Task => $this->tasks->delete($task->id()));
        $this->assertRefused('unknown', fn (): Task => $this->tasks->create($this->website->id(), 'Peek', sectionId: $this->secret->id()));
        $this->assertRefused('not_allowed', fn () => $this->projects->edit($this->website->id(), 'Shop', null, ''));
        $this->assertRefused('not_allowed', fn () => $this->sections->rename($this->design->id(), 'Art'));
    }

    public function testAGuestWithASharedSectionSeesItAloneAndAddsTasksThere(): void
    {
        $this->sharing->shareSection($this->design->id(), $this->guest->id());
        $this->signIn($this->guest);

        self::assertSame(['Website'], array_map(static fn (ProjectRow $row): string => $row->name, $this->queries->projects()));
        self::assertSame(['' => [], 'Design' => ['Draw the logo']], $this->list($this->website));
        $task = $this->tasks->create($this->website->id(), 'Sketch', sectionId: $this->design->id());
        self::assertSame($this->design->id(), $task->section()?->id());
        $this->assertRefused('unknown', fn (): Task => $this->tasks->create($this->website->id(), 'Loose'));
        $this->assertRefused('unknown', fn () => $this->tasks->moveToSection($task->id(), null));
    }

    public function testAGuestWithASharedTaskSeesItWithItsSubtasksAndNoSibling(): void
    {
        $this->sharing->shareTask($this->brief->id(), $this->guest->id());
        $this->signIn($this->guest);

        self::assertSame([], $this->queries->projects());
        self::assertNull($this->queries->projectList($this->website->id()));
        $detail = $this->queries->task($this->brief->id());
        self::assertNotNull($detail);
        self::assertSame(['Find a font'], array_map(static fn (TaskRow $row): string => $row->title, $detail->task->subtasks));
        self::assertSame('Website', $detail->task->projectName);
        self::assertSame([], $detail->sections);
        self::assertNotNull($this->queries->task($this->subtask->id()));
        self::assertNull($this->queries->task($this->logo->id()));
        self::assertFalse($this->visibility->canSeeProject($this->website));
        self::assertTrue($this->visibility->canSeeProjectName($this->website));
        self::assertFalse($this->visibility->canSeeProjectName($this->chosen));

        // The task moves along its workflow, and gets a subtask, but no sibling.
        [, $inProgress] = $this->statuses($this->website);
        $this->tasks->changeStatus($this->brief->id(), $inProgress->id());
        $this->tasks->create($this->website->id(), 'Pick colours', parentId: $this->brief->id());
        $this->assertRefused('unknown', fn (): Task => $this->tasks->create($this->website->id(), 'Sibling'));
    }

    public function testOnlyPeopleWhoSeeATaskAreMentionedOrMadeResponsible(): void
    {
        $this->sharing->shareTask($this->offer->id(), $this->guest->id());

        self::assertSame(['Greta Guest', 'Olga Owner'], array_map(static fn (MentionCandidate $person): string => $person->name, $this->queries->mentionCandidates($this->offer->id()) ?? []));
        self::assertSame(['Greta Guest', 'Olga Owner'], array_map(static fn (Choice $person): string => $person->name, $this->queries->task($this->offer->id())->people ?? []));
        $this->assertRefused('responsible', fn () => $this->tasks->assign($this->offer->id(), $this->member->id()));
        $this->assertRefused('watcher', fn () => $this->tasks->watch($this->offer->id(), $this->member->id()));
        $this->tasks->assign($this->offer->id(), $this->guest->id());

        // An internal comment mentions the people of the organisation alone.
        $comment = $this->comments->add($this->offer->id(), sprintf('@[Greta Guest](membership:%s) @[Olga Owner](membership:%s)', $this->guest->id(), $this->owner->id()));
        $mentioned = array_map(static fn (CommentMention $mention): string => $mention->membership()->id(), self::getContainer()->get(Comments::class)->mentions($comment));
        self::assertSame([$this->owner->id()], $mentioned);
    }

    public function testADeactivatedPersonSeesNothingAndIsNobodyToShareWith(): void
    {
        $this->sharing->shareProject($this->chosen->id(), $this->member->id());
        self::assertTrue($this->visibility->canMembershipSeeTask($this->member, $this->offer));

        $this->member->deactivate($this->clock->now());
        $this->em->flush();
        self::assertFalse($this->visibility->canMembershipSeeTask($this->member, $this->offer));
        $this->signIn($this->member);
        self::assertFalse($this->visibility->canManage());
        self::assertSame([], $this->queries->projects());
        self::assertNull($this->queries->task($this->brief->id()));
    }

    public function testATrashedPrivateTaskStaysHiddenFromWhoDoesNotSeeIt(): void
    {
        $this->tasks->delete($this->private->id());
        $this->tasks->delete($this->logo->id());

        $this->signIn($this->member);
        self::assertSame(['Draw the logo'], array_map(static fn (TrashedTask $task): string => $task->title, $this->queries->trash($this->website->id())->tasks ?? []));
        self::assertSame(1, $this->queries->trashCount($this->website->id()));
        $this->assertRefused('unknown', fn (): Task => $this->tasks->restore($this->private->id()));
    }

    /**
     * @return array<string, list<string>> the titles of the top-level tasks by section name
     */
    private function list(Project $project): array
    {
        $list = $this->queries->projectList($project->id());
        self::assertNotNull($list);
        $sections = [];
        foreach ($list->sections as $section) {
            $sections[$section->name ?? ''] = array_map(static fn (TaskRow $row): string => $row->title, $section->tasks);
        }

        return $sections;
    }

    private function assertRefused(string $reason, callable $change): void
    {
        try {
            $change();
            self::fail(sprintf('Expected the refusal "%s".', $reason));
        } catch (WorkRefused $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }
}
