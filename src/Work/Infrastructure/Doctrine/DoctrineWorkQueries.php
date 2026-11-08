<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Doctrine;

use App\Attachment\Application\VisibleAttachments;
use App\Attachment\Domain\Attachment;
use App\Audit\Application\AuditActor;
use App\Identity\Application\OwnerCheck;
use App\Identity\Application\People;
use App\Identity\Application\UserRepository;
use App\Identity\Domain\Membership;
use App\Shared\Application\Markdown;
use App\Tenancy\Application\TenantClock;
use App\Ui\Avatar;
use App\Work\Application\Choice;
use App\Work\Application\CommentAttachment;
use App\Work\Application\Comments;
use App\Work\Application\CommentView;
use App\Work\Application\DiscussionFilter;
use App\Work\Application\DiscussionItem;
use App\Work\Application\DueBucket;
use App\Work\Application\LabelBadge;
use App\Work\Application\Labels;
use App\Work\Application\MentionCandidate;
use App\Work\Application\ProjectBoard;
use App\Work\Application\ProjectList;
use App\Work\Application\ProjectRow;
use App\Work\Application\Projects;
use App\Work\Application\ProjectSettings;
use App\Work\Application\ProjectSummary;
use App\Work\Application\ProjectTrash;
use App\Work\Application\SectionTasks;
use App\Work\Application\StatusChoice;
use App\Work\Application\StatusTasks;
use App\Work\Application\TaskDetail;
use App\Work\Application\TaskFilter;
use App\Work\Application\TaskGroup;
use App\Work\Application\TaskHistory;
use App\Work\Application\TaskRow;
use App\Work\Application\Tasks;
use App\Work\Application\TrashedTask;
use App\Work\Application\TrashPermission;
use App\Work\Application\WorkQueries;
use App\Work\Application\WorkVisibility;
use App\Work\Domain\Label;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\Section;
use App\Work\Domain\StatusType;
use App\Work\Domain\Task;
use App\Work\Domain\TaskComment;
use App\Work\Domain\TaskLabel;
use App\Work\Domain\TaskWatcher;
use App\Work\Infrastructure\Attachment\TaskAttachmentAccess;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The read side in DQL, under the tenant filter. Each query asks WorkVisibility
 * for the scope first; a scope it does not know yet shows nothing, so a new
 * kind of sharing never leaks through a query not taught about it. A project is
 * read whole and its tasks are put in order in PHP: one query for the tasks and
 * one for their labels, whatever the view. A task in the trash is left out of
 * every view but the trash, and of every count.
 */
final readonly class DoctrineWorkQueries implements WorkQueries
{
    private const string TASKS = 'SELECT t, s, r, u, p FROM '.Task::class.' t JOIN t.status s JOIN t.project p LEFT JOIN t.responsible r LEFT JOIN r.user u';
    private const string ORDER = ' ORDER BY t.position ASC, t.createdAt ASC, t.id ASC';
    /** What the trash shows: a task that went there itself, not a subtask that went with its task. */
    private const string TRASHED = 'SELECT t, pa FROM '.Task::class.' t LEFT JOIN t.parent pa WHERE t.project = :project AND t.deletedAt IS NOT NULL AND (pa.id IS NULL OR pa.deletedAt IS NULL OR pa.deletedAt <> t.deletedAt)';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private WorkVisibility $visibility,
        private Projects $projects,
        private Tasks $tasks,
        private Labels $labels,
        private People $people,
        private UserRepository $users,
        private AuditActor $actor,
        private TenantClock $clock,
        private TaskHistory $history,
        private Comments $comments,
        private Markdown $markdown,
        private VisibleAttachments $attachments,
        private OwnerCheck $owner,
        private TrashPermission $trash,
    ) {
    }

    public function projects(bool $archived = false): array
    {
        if (!$this->visibility->scope()->isEverything()) {
            return [];
        }
        /** @var list<Project> $projects */
        $projects = $this->entityManager
            ->createQuery('SELECT p, c FROM '.Project::class.' p LEFT JOIN p.client c WHERE p.archivedAt IS '.($archived ? 'NOT ' : '').'NULL ORDER BY p.name ASC, p.id ASC')
            ->getResult();
        /** @var list<array{project: string, open: int|string, due: string|\DateTimeInterface|null}> $counts */
        $counts = $this->entityManager
            ->createQuery('SELECT IDENTITY(t.project) AS project, COUNT(t.id) AS open, MIN(t.dueOn) AS due FROM '.Task::class.' t JOIN t.status s WHERE s.type IN (:unfinished) AND t.deletedAt IS NULL GROUP BY t.project')
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
        ), $projects);
    }

    public function projectList(string $projectId, TaskFilter $filter = new TaskFilter()): ?ProjectList
    {
        $project = $this->visibleProject($projectId);
        if (!$project instanceof Project) {
            return null;
        }
        $rows = $this->filtered($this->tree($this->projectTasks($project)), $filter);
        $sections = [new SectionTasks(null, null, array_values(array_filter($rows, static fn (TaskRow $row): bool => null === $row->sectionId)))];
        foreach ($this->projects->sections($project) as $section) {
            $sections[] = new SectionTasks($section->id(), $section->name(), array_values(array_filter($rows, static fn (TaskRow $row): bool => $row->sectionId === $section->id())));
        }

        return new ProjectList($this->summary($project), $sections);
    }

    public function board(string $projectId, TaskFilter $filter = new TaskFilter()): ?ProjectBoard
    {
        $project = $this->visibleProject($projectId);
        if (!$project instanceof Project) {
            return null;
        }
        $rows = $this->filtered($this->tree($this->projectTasks($project)), $filter);
        $columns = [];
        foreach ($this->projects->statuses($project) as $status) {
            $columns[] = new StatusTasks($status->id(), $status->name(), $status->type(), array_values(array_filter($rows, static fn (TaskRow $row): bool => $row->statusId === $status->id())));
        }

        return new ProjectBoard($this->summary($project), $columns);
    }

    public function projectSettings(string $projectId): ?ProjectSettings
    {
        $project = $this->visibleProject($projectId);
        if (!$project instanceof Project) {
            return null;
        }

        return new ProjectSettings(
            $this->summary($project),
            array_map(static fn (Section $section): Choice => new Choice($section->id(), $section->name()), $this->projects->sections($project)),
            array_map(static fn (ProjectStatus $status): StatusChoice => new StatusChoice($status->id(), $status->name(), $status->type()), $this->projects->statuses($project)),
        );
    }

    public function trash(string $projectId): ?ProjectTrash
    {
        $project = $this->visibleProject($projectId);
        if (!$project instanceof Project) {
            return null;
        }
        /** @var list<Task> $tasks */
        $tasks = $this->entityManager
            ->createQuery(self::TRASHED.' ORDER BY t.deletedAt DESC, t.id DESC')
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
        if (!$project instanceof Project) {
            return 0;
        }
        $count = $this->entityManager
            ->createQuery(str_replace('SELECT t, pa ', 'SELECT COUNT(t.id) ', self::TRASHED))
            ->setParameter('project', $project->id())
            ->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function myTasks(?string $clientId = null): array
    {
        $membership = $this->me();
        $rows = $membership instanceof Membership && $this->visibility->scope()->isEverything()
            ? $this->sorted($this->flat($this->openTasks('t.responsible = :me', $membership, $clientId)))
            : [];

        return TaskGroup::split($rows, $this->clock->today());
    }

    public function myDueCount(): int
    {
        $membership = $this->me();
        if (!$membership instanceof Membership || !$this->visibility->scope()->isEverything()) {
            return 0;
        }
        $count = $this->entityManager
            ->createQuery('SELECT COUNT(t.id) FROM '.Task::class.' t JOIN t.status s JOIN t.project p WHERE t.responsible = :me AND s.type IN (:unfinished) AND p.archivedAt IS NULL AND t.deletedAt IS NULL AND t.dueOn <= :today')
            ->setParameter('me', $membership->id())
            ->setParameter('unfinished', $this->unfinished())
            ->setParameter('today', $this->clock->today()->format('Y-m-d'))
            ->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function watchedTasks(?string $clientId = null): array
    {
        $membership = $this->me();
        if (!$membership instanceof Membership || !$this->visibility->scope()->isEverything()) {
            return [];
        }

        return $this->sorted($this->flat($this->openTasks('EXISTS (SELECT w.id FROM '.TaskWatcher::class.' w WHERE w.task = t AND w.membership = :me)', $membership, $clientId)));
    }

    public function task(string $taskId): ?TaskDetail
    {
        $task = $this->tasks->task($taskId);
        if (!$task instanceof Task || !$this->visibility->canSeeTask($task)) {
            return null;
        }
        $project = $task->project();
        $subtasks = $this->tasks->siblings($project, $task);
        $labels = $this->labelsOf([$task, ...$subtasks]);
        $deletable = $this->trash->rule();
        $subtaskRows = array_map(fn (Task $subtask): TaskRow => $this->row($subtask, $labels, $deletable, 0), $subtasks);
        /** @var list<TaskWatcher> $watchers */
        $watchers = $this->entityManager
            ->createQuery('SELECT w, m, u FROM '.TaskWatcher::class.' w JOIN w.membership m JOIN m.user u WHERE w.task = :task ORDER BY u.displayName ASC, w.id ASC')
            ->setParameter('task', $task->id())
            ->getResult();
        $people = [];
        foreach ($this->people->all() as $person) {
            if ($person->active) {
                $people[] = new Choice($person->membershipId, $person->name);
            }
        }
        $parent = $task->parent();

        return new TaskDetail(
            $this->row($task, $labels, $deletable, \count($subtasks), $subtaskRows),
            $task->description(),
            $parent instanceof Task ? new Choice($parent->id(), $parent->title()) : null,
            $task->section()?->name(),
            array_map(static fn (TaskWatcher $watcher): Choice => new Choice($watcher->membership()->id(), $watcher->membership()->user()->displayName()), $watchers),
            array_map(static fn (ProjectStatus $status): StatusChoice => new StatusChoice($status->id(), $status->name(), $status->type()), $this->projects->statuses($project)),
            array_map(static fn (Section $section): Choice => new Choice($section->id(), $section->name()), $this->projects->sections($project)),
            $people,
            $project->isArchived(),
            $task->createdAt(),
            $this->users->namesOf([$task->createdBy()])[$task->createdBy()] ?? null,
            $task->completedAt(),
        );
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
            foreach ($this->comments->of($task) as $comment) {
                $comments[] = DiscussionItem::comment($this->commentView($comment, $me, $owner));
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

    public function labels(): array
    {
        if (!$this->visibility->scope()->isEverything()) {
            return [];
        }

        return array_map(static fn (Label $label): LabelBadge => new LabelBadge($label->id(), $label->name(), $label->colour()), $this->labels->all());
    }

    public function labelUsage(): array
    {
        if (!$this->visibility->scope()->isEverything()) {
            return [];
        }
        /** @var list<array{label: string, tasks: int|string}> $counts */
        $counts = $this->entityManager
            ->createQuery('SELECT IDENTITY(tl.label) AS label, COUNT(tl.id) AS tasks FROM '.TaskLabel::class.' tl JOIN tl.task t WHERE t.deletedAt IS NULL GROUP BY tl.label')
            ->getArrayResult();

        return array_map(intval(...), array_column($counts, 'tasks', 'label'));
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
            $deleted ? [] : array_map(
                static fn (Attachment $file): CommentAttachment => new CommentAttachment($file->id(), $file->fileName(), $file->mediaType(), $file->size()),
                $this->attachments->of(TaskAttachmentAccess::COMMENT_KIND, $comment->id()),
            ),
        );
    }

    private function visibleProject(string $id): ?Project
    {
        $project = $this->projects->project($id);

        return $project instanceof Project && $this->visibility->canSeeProject($project) ? $project : null;
    }

    private function summary(Project $project): ProjectSummary
    {
        return new ProjectSummary(
            $project->id(),
            $project->name(),
            $project->client()?->id(),
            $project->client()?->displayName(),
            $project->description(),
            $project->workflowTemplate(),
            $project->archivedAt(),
        );
    }

    /**
     * @return list<Task> top-level tasks and subtasks, by position
     */
    private function projectTasks(Project $project): array
    {
        /** @var list<Task> $tasks */
        $tasks = $this->entityManager
            ->createQuery(self::TASKS.' WHERE t.project = :project AND t.deletedAt IS NULL'.self::ORDER)
            ->setParameter('project', $project->id())
            ->getResult();

        return $tasks;
    }

    /**
     * Unfinished tasks, subtasks included, of the active projects.
     *
     * @return list<Task>
     */
    private function openTasks(string $condition, Membership $me, ?string $clientId): array
    {
        $query = $this->entityManager
            ->createQuery(self::TASKS.' WHERE '.$condition.' AND s.type IN (:unfinished) AND p.archivedAt IS NULL AND t.deletedAt IS NULL'.(null === $clientId ? '' : ' AND IDENTITY(p.client) = :client').self::ORDER)
            ->setParameter('me', $me->id())
            ->setParameter('unfinished', $this->unfinished());
        if (null !== $clientId) {
            $query->setParameter('client', $clientId);
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
        $deletable = $this->trash->rule();
        $children = [];
        foreach ($tasks as $task) {
            $parent = $task->parent();
            if ($parent instanceof Task) {
                $children[$parent->id()][] = $this->row($task, $labels, $deletable, 0);
            }
        }
        $rows = [];
        foreach ($tasks as $task) {
            if (!$task->isSubtask()) {
                $subtasks = $children[$task->id()] ?? [];
                $rows[] = $this->row($task, $labels, $deletable, \count($subtasks), $subtasks);
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
    private function flat(array $tasks): array
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

        $deletable = $this->trash->rule();

        return array_map(fn (Task $task): TaskRow => $this->row($task, $labels, $deletable, (int) ($subtasks[$task->id()] ?? 0)), $tasks);
    }

    /**
     * @param array<string, list<LabelBadge>> $labels    by task id
     * @param \Closure(string): bool          $deletable whether the person may delete a task, by who created it
     * @param list<TaskRow>                   $subtasks
     */
    private function row(Task $task, array $labels, \Closure $deletable, int $subtaskCount, array $subtasks = []): TaskRow
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
        );
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
            ->createQuery('SELECT tl, l FROM '.TaskLabel::class.' tl JOIN tl.label l WHERE tl.task IN (:tasks) ORDER BY l.name ASC, l.id ASC')
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

        return array_values(array_filter($rows, static function (TaskRow $row) use ($filter, $today, $week): bool {
            if (null !== $filter->responsibleId && $row->responsibleId !== $filter->responsibleId) {
                return false;
            }
            if ($filter->statusType instanceof StatusType && $row->statusType !== $filter->statusType) {
                return false;
            }
            if (null !== $filter->finished && $row->done !== $filter->finished) {
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
