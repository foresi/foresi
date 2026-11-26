<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Foresi\Attachment\Application\VisibleAttachments;
use Foresi\Attachment\Domain\Attachment;
use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Application\OwnerCheck;
use Foresi\Identity\Application\People;
use Foresi\Identity\Application\UserRepository;
use Foresi\Identity\Domain\Membership;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Application\Markdown;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Contract\Field\Fields;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Infrastructure\View\DqlConditions;
use Foresi\Ui\Avatar;
use Foresi\Work\Application\Choice;
use Foresi\Work\Application\CommentAttachment;
use Foresi\Work\Application\Comments;
use Foresi\Work\Application\CommentView;
use Foresi\Work\Application\DiscussionFilter;
use Foresi\Work\Application\DiscussionItem;
use Foresi\Work\Application\DueBucket;
use Foresi\Work\Application\LabelBadge;
use Foresi\Work\Application\Labels;
use Foresi\Work\Application\MentionCandidate;
use Foresi\Work\Application\ProjectBoard;
use Foresi\Work\Application\ProjectDetail;
use Foresi\Work\Application\ProjectList;
use Foresi\Work\Application\ProjectRow;
use Foresi\Work\Application\Projects;
use Foresi\Work\Application\ProjectSettings;
use Foresi\Work\Application\ProjectSliceFilter;
use Foresi\Work\Application\ProjectSummary;
use Foresi\Work\Application\ProjectTrash;
use Foresi\Work\Application\SectionTasks;
use Foresi\Work\Application\SharedProject;
use Foresi\Work\Application\SharedTaskGroup;
use Foresi\Work\Application\SharedWork;
use Foresi\Work\Application\StatusChoice;
use Foresi\Work\Application\StatusTasks;
use Foresi\Work\Application\TaskDetail;
use Foresi\Work\Application\TaskFilter;
use Foresi\Work\Application\TaskGroup;
use Foresi\Work\Application\TaskHistory;
use Foresi\Work\Application\TaskIndex;
use Foresi\Work\Application\TaskRow;
use Foresi\Work\Application\Tasks;
use Foresi\Work\Application\TaskSearch;
use Foresi\Work\Application\TaskSliceFilter;
use Foresi\Work\Application\TrashedLabel;
use Foresi\Work\Application\TrashedTask;
use Foresi\Work\Application\TrashPermission;
use Foresi\Work\Application\View\MyTaskLists;
use Foresi\Work\Application\View\ProjectLists;
use Foresi\Work\Application\View\ProjectTaskLists;
use Foresi\Work\Application\View\TaskConditions;
use Foresi\Work\Application\View\TaskLists;
use Foresi\Work\Application\WorkQueries;
use Foresi\Work\Application\WorkScope;
use Foresi\Work\Application\WorkVisibility;
use Foresi\Work\Contract\Field\ProjectExtension;
use Foresi\Work\Contract\Field\TaskExtension;
use Foresi\Work\Domain\Label;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectStatus;
use Foresi\Work\Domain\Section;
use Foresi\Work\Domain\StatusType;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\TaskComment;
use Foresi\Work\Domain\TaskLabel;
use Foresi\Work\Domain\TaskStar;
use Foresi\Work\Domain\TaskWatcher;
use Foresi\Work\Domain\TimeEntry;
use Foresi\Work\Infrastructure\Attachment\TaskAttachmentAccess;

/**
 * The read side in DQL. Each query asks WorkVisibility for the scope first and
 * keeps to it through ScopedDql, or through WorkScope for what it already holds
 * in PHP: a private task, a private section, a task of a project the person
 * does not see are left out of every view and count as if they did not exist. A
 * project is read with the tasks the person sees and they are put in order in
 * PHP: one query for the tasks and one for their labels, whatever the view. A
 * task in the trash is left out of every view but the trash, and of every
 * count.
 */
final readonly class DoctrineWorkQueries implements WorkQueries
{
    /** The columns of the fields of ProjectLists; archived is a date, compiled as a boolean. */
    private const array PROJECT_COLUMNS = [
        ProjectLists::CLIENT => 'IDENTITY(p.client)',
        ProjectLists::VISIBILITY => 'p.visibility',
        ProjectLists::BILLABLE => 'p.billableByDefault',
    ];
    /** The tasks I am responsible for, by the membership bound as :me. */
    private const string RESPONSIBLE = 't.responsible = :me';
    private const string TASKS = 'SELECT t, s, r, u, p FROM '.Task::class.' t JOIN t.status s JOIN t.project p LEFT JOIN t.responsible r LEFT JOIN r.user u';
    private const string ORDER = ' ORDER BY t.position ASC, t.createdAt ASC, t.id ASC';
    /** What the trash shows: a task that went there itself, not a subtask that went with its task. */
    /** The longest text a search looks for. */
    private const int MAX_TEXT = 100;
    private const string TRASHED = 't.project = :project AND t.deletedAt IS NOT NULL AND (pa.id IS NULL OR pa.deletedAt IS NULL OR pa.deletedAt <> t.deletedAt)';

    public function __construct(private ProjectLists $projectLists,
        private TaskLists $taskLists,
        private EntityManagerInterface $entityManager,
        private WorkVisibility $visibility,
        private Projects $projects,
        private Tasks $tasks,
        private Labels $labels,
        private People $people,
        private UserRepository $users,
        private AuditActor $actor,
        private InstanceClock $clock,
        private TaskHistory $history,
        private Comments $comments,
        private Markdown $markdown,
        private VisibleAttachments $attachments,
        private OwnerCheck $owner,
        private TrashPermission $trash,
        private ProjectTaskLists $projectTaskLists,
        private Fields $fields,
    ) {
    }

    public function projects(bool $archived = false): array
    {
        $scope = $this->visibility->scope();
        $seen = ScopedDql::projects($scope, 'p');
        /** @var list<Project> $projects */
        $projects = $seen->apply($this->entityManager
            ->createQuery('SELECT p, c FROM '.Project::class.' p LEFT JOIN p.client c WHERE p.archivedAt IS '.($archived ? 'NOT ' : '').'NULL'.$seen->where.' ORDER BY p.name ASC, p.id ASC'))
            ->getResult();

        return $this->projectRows($projects, $scope);
    }

    public function projectIndex(ViewFilter $view, bool $descending = false, string $text = ''): array
    {
        $scope = $this->visibility->scope();
        $seen = ScopedDql::projects($scope, 'p');
        $derived = static fn (Condition $condition): array => [match ($condition->field) {
            ProjectLists::ARCHIVED => DqlConditions::derivedBoolean($condition, 'p.archivedAt IS NOT NULL'),
            default => throw new \LogicException(\sprintf('The field "%s" of the projects has no SQL.', $condition->field)),
        }, []];
        [$condition, $parameters] = new DqlConditions($this->projectLists->list(), self::PROJECT_COLUMNS, $derived(...))->compile($view, $this->clock->today(...), 'v');
        // The archived projects only when the view asks for them, as the Active tab showed.
        $archived = [] === $view->on(ProjectLists::ARCHIVED) ? 'p.archivedAt IS NULL AND ' : '';
        $direction = $descending ? 'DESC' : 'ASC';
        $text = trim(mb_substr($text, 0, self::MAX_TEXT));
        $found = '' === $text ? '' : " AND (LOWER(p.name) LIKE :text ESCAPE '!' OR LOWER(c.displayName) LIKE :text ESCAPE '!'"
            .implode('', array_map(static fn (string $field): string => ' OR '.$field, $this->fields->searched(ProjectExtension::RECORD, 'p', 'text'))).')';
        $query = $seen->apply($this->entityManager
            ->createQuery('SELECT p, c FROM '.Project::class.' p LEFT JOIN p.client c WHERE '.$archived.$condition.$found.$seen->where.' ORDER BY p.name '.$direction.', p.id '.$direction));
        if ('' !== $text) {
            $query->setParameter('text', '%'.$this->like(mb_strtolower($text)).'%');
        }
        foreach ($parameters as $name => [$value, $type]) {
            $query->setParameter($name, $value, $type);
        }
        /** @var list<Project> $projects */
        $projects = $query->getResult();

        return $this->projectRows($projects, $scope);
    }

    /**
     * The rows of the projects, each with what is left of it: one query counts the
     * unfinished tasks the person sees and finds the nearest due date of each.
     *
     * @param list<Project> $projects
     *
     * @return list<ProjectRow>
     */
    private function projectRows(array $projects, WorkScope $scope): array
    {
        if ([] === $projects) {
            return [];
        }
        // The custom fields of the rows, read once for their columns (ADR 0045).
        $this->fields->values(ProjectExtension::RECORD, array_map(static fn (Project $project): string => $project->id(), $projects));
        $tasks = ScopedDql::tasks($scope, 't');
        /** @var list<array{project: string, open: int|string, due: string|\DateTimeInterface|null}> $counts */
        $counts = $tasks->apply($this->entityManager
            ->createQuery('SELECT IDENTITY(t.project) AS project, COUNT(t.id) AS open, MIN(t.dueOn) AS due FROM '.Task::class.' t JOIN t.status s'.$tasks->joins.' WHERE s.type IN (:unfinished) AND t.deletedAt IS NULL'.$tasks->where.' GROUP BY t.project'))
            ->setParameter('unfinished', $this->unfinished())
            ->getArrayResult();
        $open = [];
        foreach ($counts as $count) {
            $due = $count['due'];
            $open[$count['project']] = [(int) $count['open'], null === $due ? null : new \DateTimeImmutable($due instanceof \DateTimeInterface ? $due->format('Y-m-d') : $due)];
        }

        return array_map(static fn (Project $project): ProjectRow => new ProjectRow(
            $project->id(),
            $project->name(),
            $project->client()?->id(),
            $project->client()?->displayName(),
            $project->workflowTemplate(),
            $open[$project->id()][0] ?? 0,
            $open[$project->id()][1] ?? null,
            $project->isArchived(),
            $project->visibility(),
        ), $projects);
    }

    public function projectList(string $projectId, TaskFilter $filter = new TaskFilter()): ?ProjectList
    {
        $project = $this->visibleProject($projectId);
        if (!$project instanceof Project) {
            return null;
        }
        $rows = $this->filtered($this->tree($this->projectTasks($project)), $this->withFields($project, $filter));
        $sections = [new SectionTasks(null, null, array_values(array_filter($rows, static fn (TaskRow $row): bool => null === $row->sectionId)))];
        foreach ($this->sections($project) as $section) {
            $sections[] = new SectionTasks($section->id(), $section->name(), array_values(array_filter($rows, static fn (TaskRow $row): bool => $row->sectionId === $section->id())), $section->isPrivate());
        }

        return new ProjectList($this->summary($project), $sections);
    }

    public function board(string $projectId, TaskFilter $filter = new TaskFilter()): ?ProjectBoard
    {
        $project = $this->visibleProject($projectId);
        if (!$project instanceof Project) {
            return null;
        }
        $rows = $this->filtered($this->tree($this->projectTasks($project)), $this->withFields($project, $filter));
        $columns = [];
        foreach ($this->projects->statuses($project) as $status) {
            $columns[] = new StatusTasks($status->id(), $status->name(), $status->type(), array_values(array_filter($rows, static fn (TaskRow $row): bool => $row->statusId === $status->id())));
        }

        return new ProjectBoard($this->summary($project), $columns);
    }

    public function projectSettings(string $projectId): ?ProjectSettings
    {
        $project = $this->visibleProject($projectId);
        if (!$project instanceof Project || !$this->visibility->canManage()) {
            return null;
        }

        $sections = $this->sections($project);

        return new ProjectSettings(
            $this->summary($project),
            array_map(static fn (Section $section): Choice => new Choice($section->id(), $section->name()), $sections),
            array_map(static fn (ProjectStatus $status): StatusChoice => new StatusChoice($status->id(), $status->name(), $status->type()), $this->projects->statuses($project)),
            array_values(array_map(static fn (Section $section): string => $section->id(), array_filter($sections, static fn (Section $section): bool => $section->isPrivate()))),
        );
    }

    public function trash(string $projectId): ?ProjectTrash
    {
        $project = $this->visibleProject($projectId);
        if (!$project instanceof Project || !$this->visibility->canManage()) {
            return null;
        }
        /** @var list<Task> $tasks */
        $tasks = $this->trashed('SELECT t, pa ', ' ORDER BY t.deletedAt DESC, t.id DESC')
            ->setParameter('project', $project->id())
            ->getResult();
        $names = $this->users->namesOf(array_values(array_unique(array_filter(array_map(static fn (Task $task): ?string => $task->deletedBy(), $tasks), \is_string(...)))));
        $restorable = $this->trash->rule();

        return new ProjectTrash($this->summary($project), array_map(fn (Task $task): TrashedTask => new TrashedTask(
            $task->id(),
            $task->title(),
            $task->parent()?->title(),
            null === $task->deletedBy() ? null : ($names[$task->deletedBy()] ?? null),
            $task->deletedAt() ?? throw new \LogicException('A task in the trash has its date.'),
            $restorable($task->createdBy()),
        ), $tasks));
    }

    public function trashCount(string $projectId): int
    {
        $project = $this->visibleProject($projectId);
        if (!$project instanceof Project || !$this->visibility->canManage()) {
            return 0;
        }
        $count = $this->trashed('SELECT COUNT(t.id) ', '')
            ->setParameter('project', $project->id())
            ->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function myTasks(): array
    {
        $membership = $this->me();
        $rows = $membership instanceof Membership
            ? $this->sorted($this->flat($this->openTasks(self::RESPONSIBLE, $membership)))
            : [];

        return TaskGroup::split($rows, $this->clock->today());
    }

    public function myDueCount(): int
    {
        $membership = $this->me();
        if (!$membership instanceof Membership) {
            return 0;
        }
        $seen = ScopedDql::tasks($this->visibility->scope(), 't');
        $count = $seen->apply($this->entityManager
            ->createQuery('SELECT COUNT(t.id) FROM '.Task::class.' t JOIN t.status s JOIN t.project p'.$seen->joins.' WHERE t.responsible = :me AND s.type IN (:unfinished) AND p.archivedAt IS NULL AND t.deletedAt IS NULL AND t.dueOn <= :today'.$seen->where))
            ->setParameter('me', $membership->id())
            ->setParameter('unfinished', $this->unfinished())
            ->setParameter('today', $this->clock->today()->format('Y-m-d'))
            ->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function watchedTasks(): array
    {
        $membership = $this->me();
        if (!$membership instanceof Membership) {
            return [];
        }

        return $this->sorted($this->flat($this->openTasks(self::watched('w'), $membership)));
    }

    public function myTaskList(ViewFilter $filter, string $text = ''): array
    {
        $membership = $this->me();
        if (!$membership instanceof Membership) {
            return [];
        }
        // Each condition's subquery has an alias of its own.
        $derived = static fn (Condition $condition, string $name): array => [DqlConditions::derivedEnum($condition, [MyTaskLists::RESPONSIBLE => self::RESPONSIBLE, MyTaskLists::WATCHER => self::watched('w'.$name)]), []];
        [$where, $parameters] = new DqlConditions(MyTaskLists::list(), [MyTaskLists::CLIENT => 'IDENTITY(p.client)'], $derived)->compile($filter, $this->clock->today(...), 'view');

        $text = trim(mb_substr($text, 0, self::MAX_TEXT));
        if ('' !== $text) {
            $where .= " AND (LOWER(t.title) LIKE :text ESCAPE '!' OR LOWER(p.name) LIKE :text ESCAPE '!')";
            $parameters['text'] = ['%'.$this->like(mb_strtolower($text)).'%', null];
        }

        return $this->sorted($this->flat($this->openTasks('('.self::RESPONSIBLE.' OR '.self::watched('w').') AND '.$where, $membership, $parameters)));
    }

    /**
     * The tasks I watch, by the membership bound as :me, with the subquery's alias.
     */
    private static function watched(string $alias): string
    {
        return \sprintf('EXISTS (SELECT %1$s.id FROM %2$s %1$s WHERE %1$s.task = t AND %1$s.membership = :me)', $alias, TaskWatcher::class);
    }

    public function task(string $taskId): ?TaskDetail
    {
        $task = $this->tasks->task($taskId);
        $scope = $this->visibility->scope();
        if (!$task instanceof Task || !$scope->seesTask($task)) {
            return null;
        }
        $project = $task->project();
        $subtasks = array_values(array_filter($this->tasks->siblings($project, $task), $scope->seesTask(...)));
        $labels = $this->labelsOf([$task, ...$subtasks]);
        $starred = $this->starredOf([$task, ...$subtasks]);
        $deletable = $this->trash->rule();
        $subtaskRows = array_map(fn (Task $subtask): TaskRow => $this->row($subtask, $labels, $deletable, $scope, 0, starred: $starred), $subtasks);
        /** @var list<TaskWatcher> $watchers */
        $watchers = $this->entityManager
            ->createQuery('SELECT w, m, u FROM '.TaskWatcher::class.' w JOIN w.membership m JOIN m.user u WHERE w.task = :task ORDER BY u.displayName ASC, w.id ASC')
            ->setParameter('task', $task->id())
            ->getResult();
        // Only someone who sees the task can be responsible for it or follow it.
        $people = [];
        foreach ($this->people->all() as $person) {
            $membership = $person->active ? $this->people->membership($person->membershipId) : null;
            if ($membership instanceof Membership && $this->visibility->canMembershipSeeTask($membership, $task)) {
                $people[] = new Choice($person->membershipId, $person->name);
            }
        }
        $parent = $task->parent();

        return new TaskDetail(
            $this->row($task, $labels, $deletable, $scope, \count($subtasks), $subtaskRows, $starred),
            $task->description(),
            $parent instanceof Task ? new Choice($parent->id(), $parent->title()) : null,
            $task->section()?->name(),
            array_map(static fn (TaskWatcher $watcher): Choice => new Choice($watcher->membership()->id(), $watcher->membership()->user()->displayName()), $watchers),
            array_map(static fn (ProjectStatus $status): StatusChoice => new StatusChoice($status->id(), $status->name(), $status->type()), $this->projects->statuses($project)),
            array_map(static fn (Section $section): Choice => new Choice($section->id(), $section->name()), array_values(array_filter($this->projects->sections($project), $scope->seesSection(...)))),
            $people,
            $project->isArchived(),
            $task->createdAt(),
            $this->users->namesOf([$task->createdBy()])[$task->createdBy()] ?? null,
            $task->completedAt(),
            $this->visibility->canShare($task),
            $this->visibility->canManage(),
            $scope->opens($project),
            // The estimate sits in the time block, which a guest never sees.
            $this->visibility->canManage() ? $task->estimateMinutes() : null,
        );
    }

    public function sharedWithMe(): SharedWork
    {
        $scope = $this->visibility->scope();
        $projects = [];
        foreach ($this->projects() as $row) {
            $project = $this->projects->project($row->id);
            if ($project instanceof Project) {
                $projects[] = new SharedProject($row, $scope->opens($project) ? [] : array_map(static fn (Section $section): string => $section->name(), $this->sections($project)));
            }
        }
        $single = [];
        foreach ($scope->taskIds() as $taskId) {
            $task = $this->tasks->task($taskId);
            if ($task instanceof Task && !$task->project()->isArchived() && !$this->listed($task, $scope)) {
                $single[] = $task;
            }
        }
        $groups = [];
        foreach ($this->flat($single) as $row) {
            $groups[$row->projectId][] = $row;
        }
        $tasks = array_map(static function (array $rows): SharedTaskGroup {
            usort($rows, static fn (TaskRow $a, TaskRow $b): int => strnatcasecmp($a->title, $b->title));

            return new SharedTaskGroup($rows[0]->projectName, $rows);
        }, array_values($groups));
        usort($tasks, static fn (SharedTaskGroup $a, SharedTaskGroup $b): int => strnatcasecmp($a->projectName, $b->projectName));

        return new SharedWork($projects, $tasks);
    }

    /**
     * Whether the list of a project the person sees shows the task already:
     * its top-level task sits in a section they see, or in none of a project open to them.
     */
    private function listed(Task $task, WorkScope $scope): bool
    {
        $project = $task->project();
        if (!$scope->seesProject($project)) {
            return false;
        }
        $parent = $task->parent();
        if ($parent instanceof Task && !$scope->seesTask($parent)) {
            return false;
        }
        $section = ($parent ?? $task)->section();

        return $section instanceof Section ? $scope->seesSection($section) : $scope->opens($project);
    }

    public function discussion(string $taskId, DiscussionFilter $filter = DiscussionFilter::All): ?array
    {
        $task = $this->tasks->task($taskId);
        if (!$task instanceof Task || !$this->visibility->canSeeTask($task)) {
            return null;
        }
        $comments = [];
        if ($filter->showsComments()) {
            $me = $this->me();
            $owner = $this->owner->isOwner();
            $internal = $this->visibility->canSeeInternal();
            foreach ($this->comments->of($task) as $comment) {
                if ($internal || !$comment->isInternal()) {
                    $comments[] = DiscussionItem::comment($this->commentView($comment, $me, $owner));
                }
            }
        }
        $steps = $filter->showsHistory() ? array_map(DiscussionItem::step(...), $this->history->of($task->id())) : [];

        // Both lists are oldest first already; a step and a comment of the same moment keep that order.
        $items = [];
        while ([] !== $steps || [] !== $comments) {
            if ([] === $comments || ([] !== $steps && $steps[0]->occurredAt <= $comments[0]->occurredAt)) {
                $items[] = array_shift($steps);
            } else {
                $items[] = array_shift($comments);
            }
        }

        return $items;
    }

    public function assignable(array $taskIds): array
    {
        if ([] === $taskIds) {
            return [];
        }
        /** @var list<Task> $tasks */
        $tasks = $this->entityManager
            ->createQuery('SELECT t FROM '.Task::class.' t WHERE t.id IN (:tasks) AND t.deletedAt IS NULL')
            ->setParameter('tasks', array_values(array_unique($taskIds)))
            ->getResult();
        $scope = $this->visibility->scope();
        // The scope of each active person once, not once per task.
        $people = [];
        foreach ($this->people->all() as $person) {
            $membership = $person->active ? $this->people->membership($person->membershipId) : null;
            if ($membership instanceof Membership && $membership->isActive()) {
                $people[] = [new Choice($person->membershipId, $person->name), $this->visibility->scopeOf($membership)];
            }
        }
        $assignable = [];
        foreach ($tasks as $task) {
            if (!$scope->seesTask($task)) {
                continue;
            }
            $assignable[$task->id()] = array_values(array_map(
                static fn (array $person): Choice => $person[0],
                array_filter($people, static fn (array $person): bool => $person[1]->seesTask($task)),
            ));
        }

        return $assignable;
    }

    public function statusesOf(array $projectIds): array
    {
        $statuses = [];
        foreach (array_unique($projectIds) as $projectId) {
            $project = $this->projects->project($projectId);
            if ($project instanceof Project && $this->visibility->canSeeProjectName($project)) {
                $statuses[$projectId] = array_map(static fn (ProjectStatus $status): StatusChoice => new StatusChoice($status->id(), $status->name(), $status->type()), $this->projects->statuses($project));
            }
        }

        return $statuses;
    }

    public function mentionCandidates(string $taskId): ?array
    {
        $task = $this->tasks->task($taskId);
        if (!$task instanceof Task || !$this->visibility->canSeeTask($task)) {
            return null;
        }
        /** @var list<Membership> $memberships */
        $memberships = $this->entityManager
            ->createQuery('SELECT m, u FROM '.Membership::class.' m JOIN m.user u WHERE m.disabledAt IS NULL ORDER BY u.displayName ASC, m.id ASC')
            ->getResult();
        $candidates = [];
        foreach ($memberships as $membership) {
            if ($membership->isActive() && $this->visibility->canMembershipSeeTask($membership, $task)) {
                $name = $membership->user()->displayName();
                $candidates[] = new MentionCandidate($membership->id(), $name, Avatar::initials($name));
            }
        }

        return $candidates;
    }

    /**
     * The labels of the organisation: anyone who has work in it puts them on a task they see.
     */
    public function labels(): array
    {
        if (!$this->me() instanceof Membership) {
            return [];
        }

        return array_map(static fn (Label $label): LabelBadge => new LabelBadge($label->id(), $label->name(), $label->colour()), $this->labels->all());
    }

    public function labelUsage(): array
    {
        if (!$this->visibility->canManage()) {
            return [];
        }
        $seen = ScopedDql::tasks($this->visibility->scope(), 't');
        /** @var list<array{label: string, tasks: int|string}> $counts */
        $counts = $seen->apply($this->entityManager
            ->createQuery('SELECT IDENTITY(tl.label) AS label, COUNT(tl.id) AS tasks FROM '.TaskLabel::class.' tl JOIN tl.task t'.$seen->joins.' WHERE t.deletedAt IS NULL'.$seen->where.' GROUP BY tl.label'))
            ->getArrayResult();

        return array_map(intval(...), array_column($counts, 'tasks', 'label'));
    }

    public function labelTrash(): array
    {
        if (!$this->visibility->canManage()) {
            return [];
        }
        $labels = $this->labels->inTrash();
        $names = $this->users->namesOf(array_values(array_unique(array_filter(array_map(static fn (Label $label): ?string => $label->deletedBy(), $labels), \is_string(...)))));

        return array_map(static fn (Label $label): TrashedLabel => new TrashedLabel(
            $label->id(),
            $label->name(),
            $label->colour(),
            null === $label->deletedBy() ? null : ($names[$label->deletedBy()] ?? null),
            $label->deletedAt() ?? throw new \LogicException('A label in the trash has its date.'),
        ), $labels);
    }

    public function taskSlice(TaskSliceFilter $filter, ?string $afterId, int $limit): array
    {
        $me = $this->me();
        $where = 't.deletedAt IS NULL';
        /** @var array<string, array{mixed, string|ArrayParameterType|null}> $parameters */
        $parameters = [];
        if (null !== $filter->projectId) {
            if (!Id::isValid($filter->projectId)) {
                return [];
            }
            $where .= ' AND p.id = :project';
            $parameters['project'] = [$filter->projectId, null];
        } else {
            $where .= ' AND p.archivedAt IS NULL';
        }
        if (TaskSliceFilter::ME === $filter->responsible) {
            if (!$me instanceof Membership) {
                return [];
            }
            $where .= ' AND '.self::RESPONSIBLE;
            $parameters['me'] = [$me->id(), null];
        } elseif (null !== $filter->responsible) {
            if (!Id::isValid($filter->responsible)) {
                return [];
            }
            $where .= ' AND r.id = :responsible';
            $parameters['responsible'] = [$filter->responsible, null];
        }
        if ($filter->statusType instanceof StatusType) {
            $where .= ' AND s.type = :statusType';
            $parameters['statusType'] = [$filter->statusType->value, null];
        }
        if (null !== $filter->finished) {
            $where .= ' AND s.type '.($filter->finished ? 'NOT ' : '').'IN (:unfinished)';
            $parameters['unfinished'] = [$this->unfinished(), ArrayParameterType::STRING];
        }
        if ($filter->due instanceof DueBucket) {
            $today = $this->clock->today()->format('Y-m-d');
            [$condition, $bound] = match ($filter->due) {
                DueBucket::Overdue => ['t.dueOn < :today AND s.type IN (:dueUnfinished)', ['today' => [$today, null], 'dueUnfinished' => [$this->unfinished(), ArrayParameterType::STRING]]],
                DueBucket::Today => ['t.dueOn = :today', ['today' => [$today, null]]],
                DueBucket::NextSevenDays => ['t.dueOn >= :today AND t.dueOn <= :week', ['today' => [$today, null], 'week' => [$this->clock->today()->modify('+7 days')->format('Y-m-d'), null]]],
                DueBucket::None => ['t.dueOn IS NULL', []],
            };
            $where .= ' AND '.$condition;
            $parameters += $bound;
        }
        $text = trim($filter->text);
        if ('' !== $text) {
            $where .= ' AND LOWER(t.title) LIKE :text ESCAPE \'!\'';
            $parameters['text'] = ['%'.$this->like(mb_strtolower(mb_substr($text, 0, self::MAX_TEXT))).'%', null];
        }
        $view = $filter->view ?? new ViewFilter();
        if ([] !== $view->conditions || $filter->open) {
            [$condition, $bound] = new TaskListDql($this->taskLists->list(), $me, $this->clock->today())->where($view, $filter->open, 'view');
            $where .= ' AND '.$condition;
            $parameters += $bound;
        }
        $seen = ScopedDql::tasks($this->visibility->scope(), 't');
        if ('' !== $filter->sort) {
            $parameters += new TaskListDql($this->taskLists->list(), $me, $this->clock->today())->meParameter();

            return $this->sortedSlice($where, $parameters, $seen, $filter->sort, $afterId, $limit);
        }
        if (null !== $afterId) {
            $where .= ' AND t.id < :after';
            $parameters['after'] = [$afterId, null];
        }
        $query = $seen->apply($this->entityManager
            ->createQuery('SELECT t, s, r, u, p, c'.TaskListDql::FROM.$seen->joins.' WHERE '.$where.$seen->where.' ORDER BY t.id DESC'))
            ->setMaxResults($limit);
        $this->bind($query, $parameters);
        /** @var list<Task> $tasks */
        $tasks = $query->getResult();

        return $this->flat($tasks);
    }

    /**
     * A slice in a sort: the ids of every task that matches in order, then the tasks of the
     * slice after the one the cursor names. The ids are read whole, as the sort leaves no
     * key to continue from; a cursor of a task no longer listed starts over.
     *
     * @param array<string, array{mixed, string|ArrayParameterType|null}> $parameters
     *
     * @return list<TaskRow>
     */
    private function sortedSlice(string $where, array $parameters, ScopedDql $seen, string $sort, ?string $afterId, int $limit): array
    {
        [$hidden, $order] = $this->ordered(TaskListDql::order(TaskLists::sort($sort, TaskLists::DEFAULT_SORT)));
        $query = $seen->apply($this->entityManager
            ->createQuery('SELECT t.id'.$hidden.TaskListDql::FROM.$seen->joins.' WHERE '.$where.$seen->where.$order));
        $this->bind($query, $parameters);
        /** @var list<string> $ids */
        $ids = array_column($query->getScalarResult(), 'id');
        $at = null === $afterId ? false : array_search($afterId, $ids, true);
        $ids = \array_slice($ids, false === $at ? 0 : $at + 1, $limit);
        if ([] === $ids) {
            return [];
        }
        /** @var list<Task> $tasks */
        $tasks = $this->entityManager
            ->createQuery('SELECT t, s, r, u, p, c'.TaskListDql::FROM.' WHERE t.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getResult();
        $byId = [];
        foreach ($tasks as $task) {
            $byId[$task->id()] = $task;
        }

        return $this->flat(array_values(array_filter(array_map(static fn (string $id): ?Task => $byId[$id] ?? null, $ids))));
    }

    public function taskIndex(TaskSearch $search, int $offset, int $limit): TaskIndex
    {
        $me = $this->me();
        $dql = new TaskListDql($this->taskLists->list(), $me, $this->clock->today());
        [$where, $parameters] = $dql->where($search->filter, true);
        $where = 't.deletedAt IS NULL AND p.archivedAt IS NULL AND '.$where;
        $text = trim(mb_substr($search->text, 0, self::MAX_TEXT));
        if ('' !== $text) {
            $where .= " AND (LOWER(t.title) LIKE :tl_text ESCAPE '!' OR LOWER(p.name) LIKE :tl_text ESCAPE '!')";
            $parameters['tl_text'] = ['%'.$this->like(mb_strtolower($text)).'%', null];
        }
        $parameters += $dql->dueParameters() + $dql->meParameter();
        $seen = ScopedDql::tasks($this->visibility->scope(), 't');
        [$hidden, $order] = $this->ordered([...TaskListDql::groupOrder($search->group), ...TaskListDql::order(TaskLists::sort($search->sort, TaskLists::DEFAULT_SORT))]);
        $query = $seen->apply($this->entityManager
            ->createQuery('SELECT t, s, r, u, p, c, pa'.$hidden.TaskListDql::FROM.$seen->joins.' WHERE '.$where.$seen->where.$order))
            ->setFirstResult(max(0, $offset))
            ->setMaxResults($limit);
        $this->bind($query, $parameters);
        /** @var list<Task> $tasks */
        $tasks = $query->getResult();

        $key = TaskListDql::groupKey($search->group);
        $counts = $seen->apply($this->entityManager
            ->createQuery('SELECT '.(null === $key ? "'' AS g" : $key.' AS g').', COUNT(t.id) AS n'.TaskListDql::FROM.$seen->joins.' WHERE '.$where.$seen->where.(null === $key ? '' : ' GROUP BY g')));
        $this->bind($counts, $parameters);
        $groups = [];
        /** @var list<array{g: mixed, n: int|string}> $rows */
        $rows = $counts->getScalarResult();
        foreach ($rows as $count) {
            $group = $count['g'];
            $group = TaskLists::GROUP_DUE === $search->group ? (TaskListDql::DUE_GROUPS[\is_numeric($group) ? (int) $group : -1] ?? '') : (\is_scalar($group) ? (string) $group : '');
            $groups[$group] = ($groups[$group] ?? 0) + (int) $count['n'];
        }

        return new TaskIndex($this->flat($tasks, true), array_sum($groups), $groups);
    }

    public function taskableProjects(string $text = '', int $limit = 20): array
    {
        $text = mb_strtolower(trim(mb_substr($text, 0, self::MAX_TEXT)));
        $userId = $this->actor->userId();
        $last = [];
        if (null !== $userId) {
            /** @var list<array{project: string, last: mixed}> $used */
            $used = $this->entityManager
                ->createQuery('SELECT IDENTITY(t.project) AS project, MAX(t.createdAt) AS last FROM '.Task::class.' t WHERE t.createdBy = :user GROUP BY t.project')
                ->setParameter('user', $userId)
                ->getScalarResult();
            foreach ($used as $row) {
                $last[$row['project']] = \is_scalar($row['last']) ? (string) $row['last'] : '';
            }
        }
        $projects = [];
        foreach ($this->projects() as $row) {
            $haystack = mb_strtolower($row->name.' '.($row->clientName ?? ''));
            $project = $this->projects->project($row->id);
            if (('' === $text || str_contains($haystack, $text)) && $project instanceof Project && $this->summary($project)->taskable) {
                $projects[] = $row;
            }
        }
        // The last used first, then by name, as projects() lists them.
        $order = array_flip(array_map(static fn (ProjectRow $row): string => $row->id, $projects));
        usort($projects, static fn (ProjectRow $a, ProjectRow $b): int => [isset($last[$a->id]) ? 0 : 1, $last[$b->id] ?? '', $order[$a->id]] <=> [isset($last[$b->id]) ? 0 : 1, $last[$a->id] ?? '', $order[$b->id]]);

        return \array_slice($projects, 0, max(1, $limit));
    }

    /**
     * The HIDDEN fields a list of order expressions needs and its ORDER BY: an expression
     * other than a path is selected under a name of its own, as DQL orders by those.
     *
     * @param list<array{string, string}> $order
     *
     * @return array{string, string}
     */
    private function ordered(array $order): array
    {
        $hidden = '';
        $by = [];
        foreach ($order as $index => [$expression, $dir]) {
            if (1 === preg_match('/^[a-z]+\.[A-Za-z]+$/', $expression)) {
                $by[] = $expression.' '.$dir;
            } else {
                $hidden .= ', '.$expression.' AS HIDDEN o'.$index;
                $by[] = 'o'.$index.' '.$dir;
            }
        }

        return [$hidden, ' ORDER BY '.implode(', ', $by)];
    }

    /**
     * Binds the parameters the query's DQL names, and only those: DQL refuses one it does not use.
     *
     * @param Query<mixed, mixed>                                         $query
     * @param array<string, array{mixed, string|ArrayParameterType|null}> $parameters
     */
    private function bind(Query $query, array $parameters): void
    {
        $dql = (string) $query->getDQL();
        foreach ($parameters as $name => [$value, $type]) {
            if (1 === preg_match('/:'.preg_quote($name, '/').'(?!\w)/', $dql)) {
                $query->setParameter($name, $value, $type);
            }
        }
    }

    public function projectSlice(ProjectSliceFilter $filter, ?string $afterId, int $limit): array
    {
        $view = $filter->view ?? new ViewFilter();
        // The archived projects instead of the active ones, unless the view decides it.
        $where = [] === $view->on(ProjectLists::ARCHIVED) ? 'p.archivedAt IS '.($filter->archived ? 'NOT ' : '').'NULL' : '1 = 1';
        /** @var array<string, array{mixed, string|ArrayParameterType|null}> $parameters */
        $parameters = [];
        if (null !== $filter->clientId) {
            if (!Id::isValid($filter->clientId)) {
                return [];
            }
            $where .= ' AND c.id = :client';
            $parameters['client'] = [$filter->clientId, null];
        }
        $text = trim($filter->text);
        if ('' !== $text) {
            $where .= ' AND LOWER(p.name) LIKE :text ESCAPE \'!\'';
            $parameters['text'] = ['%'.$this->like(mb_strtolower(mb_substr($text, 0, self::MAX_TEXT))).'%', null];
        }
        if ([] !== $view->conditions) {
            $derived = static fn (Condition $condition): array => [match ($condition->field) {
                ProjectLists::ARCHIVED => DqlConditions::derivedBoolean($condition, 'p.archivedAt IS NOT NULL'),
                default => throw new \LogicException(\sprintf('The field "%s" of the projects has no SQL.', $condition->field)),
            }, []];
            [$condition, $bound] = new DqlConditions($this->projectLists->list(), self::PROJECT_COLUMNS, $derived(...))->compile($view, $this->clock->today(...), 'v');
            $where .= ' AND '.$condition;
            $parameters += $bound;
        }
        if (null !== $afterId) {
            $where .= ' AND p.id < :after';
            $parameters['after'] = [$afterId, null];
        }
        $scope = $this->visibility->scope();
        $seen = ScopedDql::projects($scope, 'p');
        $query = $seen->apply($this->entityManager
            ->createQuery('SELECT p, c FROM '.Project::class.' p LEFT JOIN p.client c WHERE '.$where.$seen->where.' ORDER BY p.id DESC'))
            ->setMaxResults($limit);
        foreach ($parameters as $name => [$value, $type]) {
            $query->setParameter($name, $value, $type);
        }
        /** @var list<Project> $projects */
        $projects = $query->getResult();

        return $this->projectRows($projects, $scope);
    }

    public function project(string $projectId): ?ProjectDetail
    {
        $project = $this->visibleProject($projectId);
        if (!$project instanceof Project) {
            return null;
        }

        return new ProjectDetail(
            $this->projectRows([$project], $this->visibility->scope())[0],
            $project->description(),
            // Billing is the organisation's: a guest is not told.
            $this->visibility->canManage() ? $project->billsTimeByDefault() : null,
            array_map(static fn (ProjectStatus $status): StatusChoice => new StatusChoice($status->id(), $status->name(), $status->type()), $this->projects->statuses($project)),
            array_map(static fn (Section $section): Choice => new Choice($section->id(), $section->name()), $this->sections($project)),
        );
    }

    public function projectPeople(string $projectId, ?string $taskId = null): ?array
    {
        $project = $this->visibleProject($projectId);
        if (!$project instanceof Project) {
            return null;
        }
        if (null !== $taskId) {
            $task = $this->tasks->task($taskId);
            if (!$task instanceof Task || $task->project()->id() !== $project->id()) {
                return null;
            }

            return $this->assignable([$taskId])[$taskId] ?? null;
        }
        // A guest is not told who else the organisation works with: its own people only, as on the project screen.
        $staff = $this->visibility->canManage();
        $people = [];
        foreach ($this->people->all() as $person) {
            $membership = $person->active && ($staff || $person->role->isStaff()) ? $this->people->membership($person->membershipId) : null;
            if ($membership instanceof Membership && $membership->isActive() && $this->visibility->scopeOf($membership)->opens($project)) {
                $people[] = new Choice($person->membershipId, $person->name);
            }
        }

        return $people;
    }

    private function commentView(TaskComment $comment, ?Membership $me, bool $owner): CommentView
    {
        $name = $comment->author()->user()->displayName();
        $deleted = $comment->isDeleted();
        $mine = $me instanceof Membership && $comment->author()->id() === $me->id();

        return new CommentView(
            $comment->id(),
            $name,
            Avatar::initials($name),
            $deleted ? '' : $comment->text(),
            $deleted ? '' : $this->markdown->toHtml($comment->text()),
            $comment->isInternal(),
            $comment->createdAt(),
            $comment->editedAt(),
            $deleted,
            !$deleted && $mine,
            !$deleted && ($mine || $owner),
            $comment->isRestorable() && ($mine || $owner),
            $deleted ? [] : array_map(
                static fn (Attachment $file): CommentAttachment => new CommentAttachment($file->id(), $file->fileName(), $file->mediaType(), $file->size()),
                $this->attachments->of(TaskAttachmentAccess::COMMENT_KIND, $comment->id()),
            ),
            $comment->author()->id(),
        );
    }

    private function visibleProject(string $id): ?Project
    {
        $project = $this->projects->project($id);

        return $project instanceof Project && $this->visibility->canSeeProject($project) ? $project : null;
    }

    private function summary(Project $project): ProjectSummary
    {
        $open = $this->visibility->scope()->opens($project);

        return new ProjectSummary(
            $project->id(),
            $project->name(),
            $project->client()?->id(),
            $project->client()?->displayName(),
            $project->description(),
            $project->workflowTemplate(),
            $project->archivedAt(),
            $project->visibility(),
            $this->visibility->canShare($project),
            $this->visibility->canManage(),
            $open,
            $open || [] !== $this->sections($project),
            $project->billsTimeByDefault(),
            $this->owner->isOwner() ? $project->hourlyRate() : null,
        );
    }

    /**
     * The sections of the project the person sees.
     *
     * @return list<Section> by position
     */
    private function sections(Project $project): array
    {
        return array_values(array_filter($this->projects->sections($project), $this->visibility->scope()->seesSection(...)));
    }

    /**
     * The tasks of the project in the trash that the person would see out of it.
     *
     * @return Query<mixed, mixed>
     */
    private function trashed(string $select, string $order): Query
    {
        $seen = ScopedDql::tasks($this->visibility->scope(), 't');

        return $seen->apply($this->entityManager->createQuery($select.'FROM '.Task::class.' t LEFT JOIN t.parent pa'.$seen->joins.' WHERE '.self::TRASHED.$seen->where.$order));
    }

    /**
     * The filter with what the query matches of the custom fields (ADR 0045): the tasks of
     * the project that hold the view's conditions on them, and those whose searched fields
     * hold the text. The rest of the filter is matched on the rows.
     */
    private function withFields(Project $project, TaskFilter $filter): TaskFilter
    {
        $conditions = $filter->conditions;
        if ($conditions instanceof TaskConditions) {
            $others = $conditions->others($this->projectTaskLists->list());
            if ([] !== $others->conditions) {
                $derived = static fn (): never => throw new \LogicException('A field of the tasks the query matches has its expression.');
                [$where, $parameters] = new DqlConditions($this->projectTaskLists->list(), [], $derived(...))->compile($others, $this->clock->today(...), 'v');
                $conditions = $conditions->matching($this->taskIds($project, $where, $parameters));
            }
        }
        $found = [];
        $text = mb_strtolower(trim(mb_substr($filter->text, 0, self::MAX_TEXT)));
        $searched = $this->fields->searched(TaskExtension::RECORD, 't', 'text');
        if ('' !== $text && [] !== $searched) {
            $found = $this->taskIds($project, '('.implode(' OR ', $searched).')', ['text' => ['%'.$this->like($text).'%', null]]);
        }

        return new TaskFilter($filter->responsibleId, $filter->statusType, $filter->due, $filter->finished, $conditions, $filter->text, $found);
    }

    /**
     * @param array<string, array{mixed, string|ArrayParameterType|null}> $parameters
     *
     * @return list<string> the tasks of the project that hold the condition
     */
    private function taskIds(Project $project, string $where, array $parameters): array
    {
        $query = $this->entityManager->createQuery('SELECT t.id FROM '.Task::class.' t WHERE t.project = :project AND '.$where)->setParameter('project', $project->id());
        foreach ($parameters as $name => [$value, $type]) {
            $query->setParameter($name, $value, $type);
        }

        return array_values(array_filter($query->getSingleColumnResult(), \is_string(...)));
    }

    /**
     * @return list<Task> top-level tasks and subtasks the person sees, by position
     */
    private function projectTasks(Project $project): array
    {
        $seen = ScopedDql::tasks($this->visibility->scope(), 't');
        /** @var list<Task> $tasks */
        $tasks = $seen->apply($this->entityManager
            ->createQuery(self::TASKS.$seen->joins.' WHERE t.project = :project AND t.deletedAt IS NULL'.$seen->where.self::ORDER))
            ->setParameter('project', $project->id())
            ->getResult();
        // The custom fields of the rows, read once for their columns (ADR 0045).
        $this->fields->values(TaskExtension::RECORD, array_map(static fn (Task $task): string => $task->id(), $tasks));

        return $tasks;
    }

    /**
     * Unfinished tasks, subtasks included, of the active projects.
     *
     * @param array<string, array{mixed, string|ArrayParameterType|null}> $parameters those of the condition, beside :me
     *
     * @return list<Task>
     */
    private function openTasks(string $condition, Membership $me, array $parameters = []): array
    {
        $seen = ScopedDql::tasks($this->visibility->scope(), 't');
        $query = $seen->apply($this->entityManager
            ->createQuery(self::TASKS.$seen->joins.' WHERE '.$condition.' AND s.type IN (:unfinished) AND p.archivedAt IS NULL AND t.deletedAt IS NULL'.$seen->where.self::ORDER))
            ->setParameter('me', $me->id())
            ->setParameter('unfinished', $this->unfinished());
        foreach ($parameters as $name => [$value, $type]) {
            $query->setParameter($name, $value, $type);
        }

        /** @var list<Task> $tasks */
        $tasks = $query->getResult();

        return $tasks;
    }

    /**
     * The top-level tasks, each with its subtasks.
     *
     * @param list<Task> $tasks by position
     *
     * @return list<TaskRow>
     */
    private function tree(array $tasks): array
    {
        $labels = $this->labelsOf($tasks);
        $starred = $this->starredOf($tasks);
        $deletable = $this->trash->rule();
        $scope = $this->visibility->scope();
        $children = [];
        foreach ($tasks as $task) {
            $parent = $task->parent();
            if ($parent instanceof Task) {
                $children[$parent->id()][] = $this->row($task, $labels, $deletable, $scope, 0, starred: $starred);
            }
        }
        $rows = [];
        foreach ($tasks as $task) {
            if (!$task->isSubtask()) {
                $subtasks = $children[$task->id()] ?? [];
                $rows[] = $this->row($task, $labels, $deletable, $scope, \count($subtasks), $subtasks, $starred);
            }
        }

        return $rows;
    }

    /**
     * Each task on its own, with the number of its subtasks.
     *
     * @param list<Task> $tasks
     *
     * @return list<TaskRow>
     */
    private function flat(array $tasks, bool $wide = false): array
    {
        if ([] === $tasks) {
            return [];
        }
        /** @var list<array{parent: string, subtasks: int|string}> $counts */
        $counts = $this->entityManager
            ->createQuery('SELECT IDENTITY(t.parent) AS parent, COUNT(t.id) AS subtasks FROM '.Task::class.' t WHERE t.parent IN (:tasks) AND t.deletedAt IS NULL GROUP BY t.parent')
            ->setParameter('tasks', array_map(static fn (Task $task): string => $task->id(), $tasks))
            ->getArrayResult();
        $subtasks = array_column($counts, 'subtasks', 'parent');
        $labels = $this->labelsOf($tasks);
        $starred = $this->starredOf($tasks);

        $deletable = $this->trash->rule();
        $scope = $this->visibility->scope();

        if (!$wide) {
            return array_map(fn (Task $task): TaskRow => $this->row($task, $labels, $deletable, $scope, (int) ($subtasks[$task->id()] ?? 0), starred: $starred), $tasks);
        }
        // The estimate and the time sit in the time block of a task, which a guest never sees.
        $manage = $this->visibility->canManage();
        $logged = $manage ? $this->logged($tasks) : [];

        return array_map(function (Task $task) use ($labels, $starred, $deletable, $scope, $subtasks, $manage, $logged): TaskRow {
            $row = $this->row($task, $labels, $deletable, $scope, (int) ($subtasks[$task->id()] ?? 0), starred: $starred);
            $client = $task->project()->client();

            return new TaskRow(
                $row->id, $row->projectId, $row->projectName, $row->sectionId, $row->parentId, $row->title, $row->statusId, $row->statusName, $row->statusType,
                $row->responsibleId, $row->responsibleName, $row->dueOn, $row->priority, $row->labels, $row->subtaskCount, $row->done, [], $row->deletable, $row->private, $row->projectSeen,
                $client?->id(),
                $client?->displayName(),
                $manage ? $task->estimateMinutes() : null,
                $manage ? ($logged[$task->id()] ?? 0) : null,
                $task->createdAt(),
                $task->updatedAt(),
                $task->parent()?->title(),
                $row->starred,
            );
        }, $tasks);
    }

    /**
     * The minutes logged on each task, the time in the trash and a running timer left out.
     *
     * @param list<Task> $tasks
     *
     * @return array<string, int> by task id
     */
    private function logged(array $tasks): array
    {
        /** @var list<array{task: string, minutes: int|string|null}> $sums */
        $sums = $this->entityManager
            ->createQuery('SELECT IDENTITY(e.task) AS task, SUM(e.minutes) AS minutes FROM '.TimeEntry::class.' e WHERE e.task IN (:tasks) AND e.deletedAt IS NULL GROUP BY e.task')
            ->setParameter('tasks', array_map(static fn (Task $task): string => $task->id(), $tasks))
            ->getScalarResult();

        return array_map(static fn (int|string|null $minutes): int => (int) $minutes, array_column($sums, 'minutes', 'task'));
    }

    /**
     * @param array<string, list<LabelBadge>> $labels    by task id
     * @param \Closure(string): bool          $deletable whether the person may delete a task, by who created it
     * @param list<TaskRow>                   $subtasks
     * @param array<string, true>             $starred   the tasks the person starred, by id
     */
    private function row(Task $task, array $labels, \Closure $deletable, WorkScope $scope, int $subtaskCount, array $subtasks = [], array $starred = []): TaskRow
    {
        $status = $task->status();
        $responsible = $task->responsible();

        return new TaskRow(
            $task->id(),
            $task->project()->id(),
            $task->project()->name(),
            $task->section()?->id(),
            $task->parent()?->id(),
            $task->title(),
            $status->id(),
            $status->name(),
            $status->type(),
            $responsible?->id(),
            $responsible?->user()->displayName(),
            $task->dueDate(),
            $task->priority(),
            $labels[$task->id()] ?? [],
            $subtaskCount,
            $status->type()->isFinished(),
            $subtasks,
            $deletable($task->createdBy()),
            $task->isPrivate(),
            $scope->seesProject($task->project()),
            starred: isset($starred[$task->id()]),
        );
    }

    /**
     * The tasks the person looking starred for My priorities, of those given.
     *
     * @param list<Task> $tasks
     *
     * @return array<string, true> by task id
     */
    private function starredOf(array $tasks): array
    {
        $me = $this->me();
        if (!$me instanceof Membership || [] === $tasks) {
            return [];
        }
        /** @var list<array{task: string}> $rows */
        $rows = $this->entityManager
            ->createQuery('SELECT IDENTITY(st.task) AS task FROM '.TaskStar::class.' st WHERE st.membership = :me AND st.task IN (:tasks)')
            ->setParameter('me', $me->id())
            ->setParameter('tasks', array_map(static fn (Task $task): string => $task->id(), $tasks))
            ->getScalarResult();

        return array_fill_keys(array_column($rows, 'task'), true);
    }

    /**
     * @param list<Task> $tasks
     *
     * @return array<string, list<LabelBadge>> by task id, each by name
     */
    private function labelsOf(array $tasks): array
    {
        if ([] === $tasks) {
            return [];
        }
        /** @var list<TaskLabel> $taskLabels */
        $taskLabels = $this->entityManager
            ->createQuery('SELECT tl, l FROM '.TaskLabel::class.' tl JOIN tl.label l WHERE tl.task IN (:tasks) AND l.deletedAt IS NULL ORDER BY l.name ASC, l.id ASC')
            ->setParameter('tasks', array_map(static fn (Task $task): string => $task->id(), $tasks))
            ->getResult();
        $labels = [];
        foreach ($taskLabels as $taskLabel) {
            $label = $taskLabel->label();
            $labels[$taskLabel->task()->id()][] = new LabelBadge($label->id(), $label->name(), $label->colour());
        }

        return $labels;
    }

    /**
     * @param list<TaskRow> $rows
     *
     * @return list<TaskRow>
     */
    private function filtered(array $rows, TaskFilter $filter): array
    {
        $today = null;
        $week = null;
        if ($filter->due instanceof DueBucket) {
            $today = $this->clock->today()->format('Y-m-d');
            $week = $this->clock->today()->modify('+7 days')->format('Y-m-d');
        }
        $text = mb_strtolower(trim(mb_substr($filter->text, 0, self::MAX_TEXT)));

        return array_values(array_filter($rows, static function (TaskRow $row) use ($filter, $today, $week, $text): bool {
            if (null !== $filter->responsibleId && $row->responsibleId !== $filter->responsibleId) {
                return false;
            }
            if ($filter->statusType instanceof StatusType && $row->statusType !== $filter->statusType) {
                return false;
            }
            if (null !== $filter->finished && $row->done !== $filter->finished) {
                return false;
            }
            if ($filter->conditions instanceof TaskConditions && !$filter->conditions->matches($row)) {
                return false;
            }
            if ('' !== $text && !self::titled($row, $text) && !\in_array($row->id, $filter->found, true)) {
                return false;
            }
            $due = $row->dueOn?->format('Y-m-d');

            return match ($filter->due) {
                null => true,
                DueBucket::Overdue => null !== $due && $due < $today && !$row->done,
                DueBucket::Today => $due === $today,
                DueBucket::NextSevenDays => null !== $due && $due >= $today && $due <= $week,
                DueBucket::None => null === $due,
            };
        }));
    }

    /**
     * Whether the title of the task, or of one of its subtasks, holds the text (lower case).
     */
    private static function titled(TaskRow $row, string $text): bool
    {
        if (str_contains(mb_strtolower($row->title), $text)) {
            return true;
        }

        return array_any($row->subtasks, fn (TaskRow $subtask): bool => self::titled($subtask, $text));
    }

    /**
     * By due date, those without one last, then by priority, highest first.
     *
     * @param list<TaskRow> $rows
     *
     * @return list<TaskRow>
     */
    private function sorted(array $rows): array
    {
        usort($rows, static fn (TaskRow $one, TaskRow $other): int => [!$one->dueOn instanceof \DateTimeImmutable, $one->dueOn?->format('Y-m-d'), -$one->priority->rank()]
            <=> [!$other->dueOn instanceof \DateTimeImmutable, $other->dueOn?->format('Y-m-d'), -$other->priority->rank()]);

        return $rows;
    }

    /**
     * The text of a LIKE, its wildcards escaped with "!".
     */
    private function like(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    private function me(): ?Membership
    {
        $userId = $this->actor->userId();
        $membership = null === $userId ? null : $this->people->membershipOf($userId);

        return $membership instanceof Membership && $membership->isActive() ? $membership : null;
    }

    /**
     * @return list<string>
     */
    private function unfinished(): array
    {
        return array_values(array_map(static fn (StatusType $type): string => $type->value, array_filter(StatusType::cases(), static fn (StatusType $type): bool => !$type->isFinished())));
    }
}
