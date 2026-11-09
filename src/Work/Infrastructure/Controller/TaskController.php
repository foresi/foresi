<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Controller;

use App\Attachment\Application\AttachmentPermissions;
use App\Attachment\Application\VisibleAttachments;
use App\Attachment\Domain\Attachment;
use App\Audit\Application\AuditActor;
use App\Identity\Application\People;
use App\Identity\Application\Person;
use App\Identity\Application\StaffCheck;
use App\Tenancy\Application\TenantClock;
use App\Ui\Avatar;
use App\Ui\FileSize;
use App\Work\Application\Choice;
use App\Work\Application\Comments;
use App\Work\Application\CommentView;
use App\Work\Application\DiscussionFilter;
use App\Work\Application\DiscussionItem;
use App\Work\Application\LabelBadge;
use App\Work\Application\ManageComments;
use App\Work\Application\ManageTasks;
use App\Work\Application\MentionCandidate;
use App\Work\Application\TaskDetail;
use App\Work\Application\TaskHistoryEntry;
use App\Work\Application\TaskRow;
use App\Work\Application\WorkQueries;
use App\Work\Application\WorkRefused;
use App\Work\Domain\Priority;
use App\Work\Infrastructure\Attachment\TaskAttachmentAccess;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The page of a task, for the staff, as the work item view of Jira and the
 * issue view of Linear: the title, the description, the subtasks and the
 * discussion on the left, the properties on the right. Each property is a form
 * of its own that saves when a value is chosen and comes back to the page;
 * the title, the description and one's own comment open their form in place.
 * The discussion is one timeline, oldest first, with the comment box under it
 * as in Linear. The files of the task are listed under the description.
 *
 * A task is deleted from the menu of its page, of its row in the list or of its
 * card on the board, without a question: it goes to the trash of its project,
 * and the message that says so has an Undo, as Gmail and Linear do it.
 */
final class TaskController extends AbstractController
{
    public const string ROUTE = 'app_task';
    public const string TOKEN = 'work_task';
    /** Deleting and restoring, from every place that offers it. */
    public const string TRASH_TOKEN = 'work_task_trash';
    /** The message of a deletion with its Undo: a message, a label, the URL that restores and the token it sends. */
    public const string UNDO_FLASH = 'work_undo';
    /** Where a deletion or a restore was asked from, and so where it goes back to. */
    private const array PLACES = ['list', 'board', 'task', 'trash'];
    private const array EDITABLE = ['title', 'description'];
    private const array STATUS_TONES = ['open' => 'gray', 'active' => 'amber', 'done' => 'green', 'closed' => 'gray'];
    /** Shown on the page: the image as a thumbnail, a PDF in a dialog, a video in a player. */
    private const array PREVIEWS = [
        'image/png' => 'image', 'image/jpeg' => 'image', 'image/gif' => 'image', 'image/webp' => 'image',
        'application/pdf' => 'pdf',
        'video/mp4' => 'video', 'video/webm' => 'video', 'video/quicktime' => 'video',
    ];
    /** The download of an attachment by its name: Work reaches the Attachment module through its application layer only. */
    private const string ATTACHMENT_ROUTE = 'app_attachment';
    private const array FILE_ICONS = ['pdf' => 'file-text', 'video' => 'file-video-camera', 'file' => 'file'];
    /** The refusals of a comment's text, told next to its field. */
    private const array COMMENT_TEXT = ['comment_empty', 'comment_too_long'];

    public function __construct(
        private readonly WorkQueries $queries,
        private readonly ManageTasks $tasks,
        private readonly ManageComments $comments,
        private readonly Comments $commentStore,
        private readonly People $people,
        private readonly StaffCheck $staff,
        private readonly AuditActor $actor,
        private readonly TenantClock $clock,
        private readonly TranslatorInterface $translator,
        private readonly VisibleAttachments $attachments,
        private readonly AttachmentPermissions $attachmentPermissions,
    ) {
    }

    #[Route('/tasks/{id}', name: self::ROUTE, requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function show(string $id, Request $request): Response
    {
        $this->denyUnlessStaff();
        $task = $this->detail($id);
        $edit = $request->query->getString('edit');
        $editComment = $request->query->getString('edit_comment');

        return $this->page(
            $task,
            \in_array($edit, self::EDITABLE, true) ? $edit : null,
            [],
            [],
            editComment: '' === $editComment ? null : $editComment,
            filter: DiscussionFilter::tryFrom($request->query->getString('activity')) ?? DiscussionFilter::All,
        );
    }

    /**
     * A comment on the task, internal unless the switch is turned off. A text
     * it cannot have is told next to the field, which keeps what was typed.
     */
    #[Route('/tasks/{id}/comments', name: 'app_task_comment_add', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function addComment(string $id, Request $request): Response
    {
        $this->denyUnlessStaff();
        $this->checkToken($request);
        $body = $request->request->getString('body');
        $internal = $request->request->has('internal');
        try {
            return $this->toComment($id, $this->comments->add($id, $body, $internal)->id());
        } catch (WorkRefused $refused) {
            $this->notFoundIfUnknown($refused);
            if (!\in_array($refused->reason, self::COMMENT_TEXT, true)) {
                $this->addFlash('error', $this->translator->trans('work.refused.'.$refused->reason));

                return $this->back($id);
            }

            return $this->page($this->detail($id), null, ['comment' => $body, 'comment_internal' => $internal ? '1' : ''], ['comment' => $this->translator->trans('work.refused.'.$refused->reason)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/comments/{id}/edit', name: 'app_task_comment_edit', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function editComment(string $id, Request $request): Response
    {
        $this->denyUnlessStaff();
        $this->checkToken($request);
        $taskId = $this->taskOfComment($id);
        $body = $request->request->getString('body');
        try {
            $this->comments->edit($id, $body);
        } catch (WorkRefused $refused) {
            $this->notFoundIfUnknown($refused);
            if (\in_array($refused->reason, self::COMMENT_TEXT, true)) {
                return $this->page($this->detail($taskId), null, ['comment_edit' => $body], ['comment_edit' => $this->translator->trans('work.refused.'.$refused->reason)], Response::HTTP_UNPROCESSABLE_ENTITY, $id);
            }
            $this->addFlash('error', $this->translator->trans('work.refused.'.$refused->reason));
        }

        return $this->toComment($taskId, $id);
    }

    /**
     * The comment stays in its place in the timeline as a note that it was deleted.
     */
    #[Route('/comments/{id}/delete', name: 'app_task_comment_delete', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function deleteComment(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessStaff();
        $this->checkToken($request);
        $taskId = $this->taskOfComment($id);
        try {
            $this->comments->delete($id);
        } catch (WorkRefused $refused) {
            $this->notFoundIfUnknown($refused);
            $this->addFlash('error', $this->translator->trans('work.refused.'.$refused->reason));
        }

        return $this->toComment($taskId, $id);
    }

    /**
     * Puts the task in the trash with its subtasks and goes back to the list
     * or the board it was deleted from; from its own page, to the list of its
     * project. The message says so and undoes it.
     */
    #[Route('/tasks/{id}/delete', name: 'app_task_delete', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function delete(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessStaff();
        $this->checkToken($request, self::TRASH_TOKEN);
        $from = $this->place($request);
        $projectId = $this->detail($id)->task->projectId;
        try {
            $task = $this->tasks->delete($id);
        } catch (WorkRefused $refused) {
            $this->notFoundIfUnknown($refused);
            $this->addFlash('error', $this->translator->trans('work.refused.'.$refused->reason));

            return 'task' === $from ? $this->back($id) : $this->toPlace($from, $projectId);
        }
        $back = 'task' === $from ? 'list' : $from;
        $this->addFlash(self::UNDO_FLASH, [
            'message' => $this->translator->trans('work.trash.deleted', ['title' => $task->title()]),
            'label' => $this->translator->trans('work.trash.undo'),
            'href' => $this->generateUrl('app_task_restore', ['id' => $id, 'from' => $back]),
            'token' => self::TRASH_TOKEN,
        ]);

        return $this->toPlace($back, $projectId);
    }

    /**
     * Takes the task out of the trash, with its task when it is a subtask, and
     * goes back to where it was asked: the trash, or the list or board whose
     * message undid a deletion.
     */
    #[Route('/tasks/{id}/restore', name: 'app_task_restore', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function restore(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessStaff();
        $this->checkToken($request, self::TRASH_TOKEN);
        $from = $this->place($request);
        try {
            $task = $this->tasks->restore($id);
        } catch (WorkRefused $refused) {
            $this->notFoundIfUnknown($refused);
            $this->addFlash('error', $this->translator->trans('work.refused.'.$refused->reason));
            // The trash says which project it is; nothing else of the task is shown to whom it is refused.
            $project = $request->request->getString('project');

            return 1 === preg_match('/^'.Requirement::UUID_V7.'$/D', $project) ? $this->toPlace($from, $project) : $this->redirectToRoute(ProjectController::ROUTE, status: Response::HTTP_SEE_OTHER);
        }
        $this->addFlash('success', $this->translator->trans('work.trash.restored', ['title' => $task->title()]));

        return 'task' === $from ? $this->back($id) : $this->toPlace($from, $task->project()->id());
    }

    /**
     * The people a comment or the description of the task can mention, for the
     * list the Markdown editor opens after "@". A task one cannot see answers 404.
     */
    #[Route('/tasks/{id}/mention-candidates', name: 'app_task_mention_candidates', requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function mentionCandidates(string $id): JsonResponse
    {
        $this->denyUnlessStaff();
        $candidates = $this->queries->mentionCandidates($id) ?? throw new NotFoundHttpException();

        return $this->json(['results' => array_map(static fn (MentionCandidate $candidate): array => [
            'id' => $candidate->id,
            'name' => $candidate->name,
            'initials' => $candidate->initials,
        ], $candidates)]);
    }

    /**
     * A title it cannot have is told next to the field, which keeps what was typed.
     */
    #[Route('/tasks/{id}/title', name: 'app_task_title', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function title(string $id, Request $request): Response
    {
        $this->denyUnlessStaff();
        $this->checkToken($request);
        $title = trim($request->request->getString('title'));
        $error = null;
        if ('' === $title) {
            $error = $this->translator->trans('work.task.error.title_required');
        } else {
            try {
                $this->tasks->rename($id, $title);

                return $this->back($id);
            } catch (WorkRefused $refused) {
                $this->notFoundIfUnknown($refused);
                $error = $this->translator->trans('work.refused.'.$refused->reason);
            }
        }

        return $this->page($this->detail($id), 'title', ['title' => $title], ['title' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/tasks/{id}/description', name: 'app_task_description', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function description(string $id, Request $request): RedirectResponse
    {
        $description = $request->request->getString('description');

        return $this->save($id, $request, fn () => $this->tasks->describe($id, $description));
    }

    #[Route('/tasks/{id}/status', name: 'app_task_status', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function status(string $id, Request $request): RedirectResponse
    {
        $status = $request->request->getString('status');

        return $this->save($id, $request, fn () => $this->tasks->changeStatus($id, $status));
    }

    #[Route('/tasks/{id}/responsible', name: 'app_task_responsible', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function responsible(string $id, Request $request): RedirectResponse
    {
        $responsible = $request->request->getString('responsible');

        return $this->save($id, $request, fn () => $this->tasks->assign($id, '' === $responsible ? null : $responsible));
    }

    /**
     * A date, or none: the clear button empties it.
     */
    #[Route('/tasks/{id}/due', name: 'app_task_due', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function due(string $id, Request $request): RedirectResponse
    {
        $value = $request->request->has('clear') ? '' : trim($request->request->getString('due'));

        return $this->save($id, $request, function () use ($id, $value): void {
            $due = '' === $value ? null : \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (false === $due || ($due instanceof \DateTimeImmutable && $due->format('Y-m-d') !== $value)) {
                $this->addFlash('error', $this->translator->trans('work.task.error.due'));

                return;
            }
            $this->tasks->setDue($id, $due);
        });
    }

    #[Route('/tasks/{id}/priority', name: 'app_task_priority', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function priority(string $id, Request $request): RedirectResponse
    {
        $priority = Priority::tryFrom($request->request->getString('priority'));

        return $this->save($id, $request, function () use ($id, $priority): void {
            $this->tasks->prioritise($id, $priority ?? throw new WorkRefused('unknown'));
        });
    }

    /**
     * The labels of the organisation to tick, saved together: a dialog over the task, or a page of its own.
     */
    #[Route('/tasks/{id}/labels', name: 'app_task_labels', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function labels(string $id, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $labels = array_values(array_filter($request->request->all('labels'), \is_string(...)));

            return $this->save($id, $request, fn () => $this->tasks->setLabels($id, $labels));
        }
        $this->denyUnlessStaff();
        $task = $this->detail($id);

        return $this->render('work/task/labels.html.twig', [
            'task' => $task,
            'labels' => $this->queries->labels(),
            'checked' => array_map(static fn (LabelBadge $label): string => $label->id, $task->task->labels),
        ]);
    }

    #[Route('/tasks/{id}/section', name: 'app_task_section', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function section(string $id, Request $request): RedirectResponse
    {
        $section = $request->request->getString('section');

        return $this->save($id, $request, fn () => $this->tasks->moveToSection($id, '' === $section ? null : $section));
    }

    #[Route('/tasks/{id}/watch', name: 'app_task_watch', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function watch(string $id, Request $request): RedirectResponse
    {
        return $this->save($id, $request, fn () => $this->tasks->watch($id, $this->myMembership() ?? throw new WorkRefused('watcher')));
    }

    #[Route('/tasks/{id}/unwatch', name: 'app_task_unwatch', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function unwatch(string $id, Request $request): RedirectResponse
    {
        return $this->save($id, $request, function () use ($id): void {
            $me = $this->myMembership();
            if (null !== $me) {
                $this->tasks->unwatch($id, $me);
            }
        });
    }

    /**
     * Another person follows the task.
     */
    #[Route('/tasks/{id}/watchers', name: 'app_task_watchers', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function watchers(string $id, Request $request): RedirectResponse
    {
        $person = $request->request->getString('person');

        return $this->save($id, $request, function () use ($id, $person): void {
            if ('' !== $person) {
                $this->tasks->watch($id, $person);
            }
        });
    }

    /**
     * A new subtask by its title, in the section of its task: a dialog over
     * the task (the same URL is a page of its own) that goes back to its
     * subtasks. A subtask has none, nor has a task of an archived project.
     */
    #[Route('/tasks/{id}/subtasks/new', name: 'app_task_subtask_new', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function newSubtask(string $id, Request $request): Response
    {
        $this->denyUnlessStaff();
        $task = $this->detail($id);
        $subtasks = $this->generateUrl(self::ROUTE, ['id' => $id, '_fragment' => 'task-subtasks-title']);
        if ($task->parent instanceof Choice || $task->projectArchived) {
            $this->addFlash('error', $this->translator->trans('work.refused.'.($task->projectArchived ? 'archived' : 'parent')));

            return $this->back($id);
        }
        $title = '';
        $error = null;
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $title = trim($request->request->getString('title'));
            if ('' === $title) {
                $error = $this->translator->trans('work.task.error.title_required');
            } else {
                try {
                    $this->tasks->create($task->task->projectId, $title, $task->task->sectionId, $id);
                    $this->addFlash('success', $this->translator->trans('work.task.subtask_added', ['title' => $title]));

                    return $this->redirect($subtasks, Response::HTTP_SEE_OTHER);
                } catch (WorkRefused $refused) {
                    $this->notFoundIfUnknown($refused);
                    $error = $this->translator->trans('work.refused.'.$refused->reason);
                }
            }
        }

        return $this->render('work/task/subtask_new.html.twig', [
            'task' => $task,
            'values' => ['title' => $title],
            'errors' => null === $error ? [] : ['title' => $error],
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * Runs the change of one property and goes back to the task; a refusal is told there.
     *
     * @param callable(): mixed $change
     */
    private function save(string $id, Request $request, callable $change): RedirectResponse
    {
        $this->denyUnlessStaff();
        $this->checkToken($request);
        try {
            $change();
        } catch (WorkRefused $refused) {
            $this->notFoundIfUnknown($refused);
            $this->addFlash('error', $this->translator->trans('work.refused.'.$refused->reason));
        }

        return $this->back($id);
    }

    /**
     * @param array<string, string>      $values what was typed into the open form
     * @param array<string, string|null> $errors by field
     */
    private function page(
        TaskDetail $task,
        ?string $edit,
        array $values,
        array $errors,
        int $status = Response::HTTP_OK,
        ?string $editComment = null,
        DiscussionFilter $filter = DiscussionFilter::All,
    ): Response {
        $people = $this->people->all();
        $seeds = array_column(array_map(static fn (Person $person): array => [$person->membershipId, $person->userId], $people), 1, 0);
        $staff = array_values(array_filter($people, static fn (Person $person): bool => $person->active && $person->role->isStaff()));
        $me = $this->myMembership();
        $watching = array_map(static fn (Choice $watcher): string => $watcher->id, $task->watchers);
        $row = $task->task;

        $responsibles = ['' => $this->translator->trans('work.task.nobody')];
        foreach ($staff as $person) {
            $responsibles[$person->membershipId] = $person->name;
        }
        // Someone no longer active stays shown as long as the task is theirs.
        if (null !== $row->responsibleId && !isset($responsibles[$row->responsibleId])) {
            $responsibles[$row->responsibleId] = $row->responsibleName ?? '';
        }
        $candidates = ['' => $this->translator->trans('work.task.watchers.choose')];
        foreach ($staff as $person) {
            if (!\in_array($person->membershipId, $watching, true)) {
                $candidates[$person->membershipId] = $person->name;
            }
        }
        $statuses = [];
        foreach ($task->statuses as $choice) {
            $statuses[$choice->id] = $choice->name;
        }
        $sections = ['' => $this->translator->trans('work.list.no_section')];
        foreach ($task->sections as $choice) {
            $sections[$choice->id] = $choice->name;
        }
        $priorities = [];
        foreach (Priority::cases() as $priority) {
            $priorities[$priority->value] = $this->translator->trans('work.priority.'.$priority->value);
        }

        $names = array_column(array_map(static fn (Person $person): array => [$person->userId, $person->name], $people), 1, 0);
        $discussion = $this->queries->discussion($row->id, $filter) ?? [];
        $editable = array_filter($discussion, static fn (DiscussionItem $item): bool => $item->comment instanceof CommentView && $item->comment->canEdit && $item->comment->id === $editComment);

        return $this->render('work/task/show.html.twig', [
            'task' => $task,
            'edit' => $edit,
            'values' => $values + ['title' => $row->title, 'description' => $task->description, 'comment' => '', 'comment_internal' => '1'],
            'errors' => array_filter($errors, \is_string(...)),
            'statusTone' => self::STATUS_TONES[$row->statusType->value],
            'statuses' => $statuses,
            'responsibles' => $responsibles,
            'priorities' => $priorities,
            'sections' => $sections,
            'candidates' => $candidates,
            'watchers' => array_map(static fn (Choice $watcher): array => [
                'name' => $watcher->name,
                'initials' => Avatar::initials($watcher->name),
                'tone' => Avatar::tone($seeds[$watcher->id] ?? $watcher->id),
            ], $task->watchers),
            'watching' => null !== $me && \in_array($me, $watching, true),
            'canWatch' => null !== $me,
            'hasLabels' => [] !== $this->queries->labels(),
            'subtaskColumns' => [
                ['key' => 'title', 'label' => $this->translator->trans('work.task.field.title'), 'truncate' => true],
                ['key' => 'status', 'label' => $this->translator->trans('work.task.field.status')],
                ['key' => 'responsible', 'label' => $this->translator->trans('work.task.field.responsible'), 'truncate' => true, 'phone' => false],
                ['key' => 'due', 'label' => $this->translator->trans('work.task.field.due_on'), 'phone' => false],
            ],
            'subtasks' => array_map(fn (TaskRow $subtask): array => $this->subtaskRow($subtask, $seeds), $row->subtasks),
            'filter' => $filter->value,
            'filters' => array_map(fn (DiscussionFilter $case): array => [
                'label' => $this->translator->trans('work.discussion.filter.'.$case->value),
                'href' => $this->generateUrl(self::ROUTE, DiscussionFilter::All === $case ? ['id' => $row->id] : ['id' => $row->id, 'activity' => $case->value]).'#task-discussion-title',
                'active' => $case === $filter,
            ], DiscussionFilter::cases()),
            'discussion' => array_map(fn (DiscussionItem $item): array => $item->comment instanceof CommentView
                ? ['comment' => $this->commentItem($item->comment)]
                : ['step' => $this->timelineItem($item->step ?? throw new \LogicException('A step.'))], $discussion),
            'editComment' => [] === $editable ? null : $editComment,
            'createdAt' => $task->createdAt->format($this->translator->trans('format.datetime')),
            'dueValue' => $row->dueOn?->format('Y-m-d') ?? '',
            'attachments' => array_map(fn (Attachment $file): array => $this->attachmentItem($file, $names), $this->attachments->of(TaskAttachmentAccess::KIND, $row->id)),
            'attachmentKind' => TaskAttachmentAccess::KIND,
        ], new Response(status: $status));
    }

    /**
     * @param array<string, string> $seeds user ids by membership id, for the avatar colours
     *
     * @return array<string, mixed>
     */
    private function subtaskRow(TaskRow $task, array $seeds): array
    {
        $due = null;
        if ($task->dueOn instanceof \DateTimeImmutable) {
            $date = $task->dueOn->format($this->translator->trans('format.date'));
            $due = !$task->done && $task->dueOn->format('Y-m-d') < $this->clock->today()->format('Y-m-d')
                ? ['text' => $this->translator->trans('work.list.overdue', ['date' => $date]), 'tone' => 'red']
                : $date;
        }

        return [
            'id' => $task->id,
            'href' => $this->generateUrl(self::ROUTE, ['id' => $task->id]),
            'label' => $task->title,
            'cells' => [
                'title' => $task->title,
                'status' => ['text' => $task->statusName, 'tone' => self::STATUS_TONES[$task->statusType->value]],
                'responsible' => null === $task->responsibleId || null === $task->responsibleName ? null : ['avatar' => $seeds[$task->responsibleId] ?? $task->responsibleId, 'text' => $task->responsibleName],
                'due' => $due,
            ],
        ];
    }

    /**
     * A file of the task as its list shows it.
     *
     * @param array<string, string> $names the names of the people by user id
     *
     * @return array<string, mixed>
     */
    private function attachmentItem(Attachment $file, array $names): array
    {
        $preview = self::PREVIEWS[$file->mediaType()] ?? 'file';

        return [
            'id' => $file->id(),
            'url' => $this->generateUrl(self::ATTACHMENT_ROUTE, ['id' => $file->id()]),
            'name' => $file->fileName(),
            'size' => FileSize::format($file->size(), $this->translator->getLocale()),
            'preview' => $preview,
            'icon' => self::FILE_ICONS[$preview] ?? null,
            'uploader' => $names[$file->uploadedBy()] ?? null,
            'time' => $file->createdAt()->format($this->translator->trans('format.datetime')),
            'datetime' => $file->createdAt()->format(\DATE_ATOM),
            'canDelete' => $file->uploadedBy() === $this->actor->userId() || $this->attachmentPermissions->canDelete($file),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function commentItem(CommentView $comment): array
    {
        $format = $this->translator->trans('format.datetime');

        return [
            'id' => $comment->id,
            'author' => $comment->authorName,
            'initials' => $comment->authorInitials,
            'tone' => Avatar::tone($comment->authorName),
            'text' => $comment->text,
            'html' => $comment->html,
            'internal' => $comment->internal,
            'time' => $comment->createdAt->format($format),
            'datetime' => $comment->createdAt->format(\DATE_ATOM),
            'edited' => $comment->editedAt?->format($format),
            'deleted' => $comment->deleted,
            'canEdit' => $comment->canEdit,
            'canDelete' => $comment->canDelete,
        ];
    }

    /**
     * One step of the history: who did it, and what changed in words.
     *
     * @return array{time: string, datetime: string, icon: string, text: string}
     */
    private function timelineItem(TaskHistoryEntry $entry): array
    {
        $parts = [];
        $icon = 'plus';
        if (TaskHistoryEntry::CREATED === $entry->event) {
            $parts[] = $this->translator->trans('work.history.created');
        } else {
            $nobody = $this->translator->trans('work.task.nobody');
            $none = $this->translator->trans('work.history.none');
            if ($entry->statusChanged) {
                $parts[] = $this->translator->trans('work.history.status', ['from' => $entry->statusFrom ?? $none, 'to' => $entry->statusTo ?? $none]);
            }
            if ($entry->reassigned) {
                $parts[] = $this->translator->trans('work.history.responsible', ['from' => $entry->assignedFrom ?? $nobody, 'to' => $entry->assignedTo ?? $nobody]);
            }
            if ($entry->dueChanged) {
                $parts[] = $this->translator->trans('work.history.due', [
                    'from' => $entry->dueFrom?->format($this->translator->trans('format.date')) ?? $this->translator->trans('work.history.no_date'),
                    'to' => $entry->dueTo?->format($this->translator->trans('format.date')) ?? $this->translator->trans('work.history.no_date'),
                ]);
            }
            if ($entry->priorityFrom instanceof Priority && $entry->priorityTo instanceof Priority) {
                $parts[] = $this->translator->trans('work.history.priority', ['from' => $this->translator->trans('work.priority.'.$entry->priorityFrom->value), 'to' => $this->translator->trans('work.priority.'.$entry->priorityTo->value)]);
            }
            if ([] !== $entry->fields) {
                $parts[] = $this->translator->trans('work.history.fields', ['fields' => implode(', ', array_map(fn (string $key): string => $this->translator->trans($key), $entry->fields))]);
            }
            foreach (['watchers_added' => $entry->watchersAdded, 'watchers_removed' => $entry->watchersRemoved, 'labels_added' => $entry->labelsAdded, 'labels_removed' => $entry->labelsRemoved] as $key => $names) {
                if ([] !== $names) {
                    $parts[] = $this->translator->trans('work.history.'.$key, ['count' => \count($names), 'names' => implode(', ', $names)]);
                }
            }
            $icon = match (true) {
                $entry->statusChanged => 'refresh-cw',
                $entry->reassigned => 'user',
                [] !== $entry->fields, $entry->dueChanged, $entry->priorityFrom instanceof Priority => 'pencil',
                [] !== $entry->watchersAdded || [] !== $entry->watchersRemoved => 'users',
                default => 'tag',
            };
        }

        return [
            'time' => $entry->occurredAt->format($this->translator->trans('format.datetime')),
            'datetime' => $entry->occurredAt->format(\DATE_ATOM),
            'icon' => $icon,
            'text' => $this->translator->trans('work.history.entry', [
                'who' => $entry->userName ?? $this->translator->trans('work.history.system'),
                'what' => implode('; ', $parts),
            ]),
        ];
    }

    private function detail(string $id): TaskDetail
    {
        return $this->queries->task($id) ?? throw new NotFoundHttpException();
    }

    private function myMembership(): ?string
    {
        $userId = $this->actor->userId();

        return null === $userId ? null : $this->people->membershipOf($userId)?->id();
    }

    /**
     * The task of a comment the person may see.
     */
    private function taskOfComment(string $id): string
    {
        $taskId = $this->commentStore->comment($id)?->task()->id() ?? throw new NotFoundHttpException();
        $this->detail($taskId);

        return $taskId;
    }

    private function toComment(string $taskId, string $commentId): RedirectResponse
    {
        return $this->redirectToRoute(self::ROUTE, ['id' => $taskId, '_fragment' => 'comment-'.$commentId], Response::HTTP_SEE_OTHER);
    }

    /**
     * @return value-of<self::PLACES>
     */
    private function place(Request $request): string
    {
        $from = $request->request->getString('from', $request->query->getString('from'));

        return \in_array($from, self::PLACES, true) ? $from : 'list';
    }

    private function toPlace(string $place, string $projectId): RedirectResponse
    {
        return $this->redirectToRoute(match ($place) {
            'board' => ProjectController::BOARD_ROUTE,
            'trash' => ProjectController::TRASH_ROUTE,
            default => ProjectController::PROJECT_ROUTE,
        }, ['id' => $projectId], Response::HTTP_SEE_OTHER);
    }

    private function back(string $id): RedirectResponse
    {
        return $this->redirectToRoute(self::ROUTE, ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    private function notFoundIfUnknown(WorkRefused $refused): void
    {
        if ('unknown' === $refused->reason) {
            throw new NotFoundHttpException($refused->getMessage(), $refused);
        }
    }

    private function denyUnlessStaff(): void
    {
        if (!$this->staff->isStaff()) {
            throw new AccessDeniedHttpException('Only the staff work on the tasks of the organisation.');
        }
    }

    private function checkToken(Request $request, string $id = self::TOKEN): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
