<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Controller;

use App\Audit\Application\AuditActor;
use App\Identity\Application\People;
use App\Identity\Application\Person;
use App\Identity\Application\StaffCheck;
use App\Tenancy\Application\TenantClock;
use App\Ui\Avatar;
use App\Work\Application\Choice;
use App\Work\Application\LabelBadge;
use App\Work\Application\ManageTasks;
use App\Work\Application\TaskDetail;
use App\Work\Application\TaskHistory;
use App\Work\Application\TaskHistoryEntry;
use App\Work\Application\TaskRow;
use App\Work\Application\WorkQueries;
use App\Work\Application\WorkRefused;
use App\Work\Domain\Priority;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
 * activity on the left, the properties on the right. Each property is a form
 * of its own that saves when a value is chosen and comes back to the page;
 * the title and the description open their form in place.
 */
final class TaskController extends AbstractController
{
    public const string ROUTE = 'app_task';
    public const string TOKEN = 'work_task';
    private const array EDITABLE = ['title', 'description'];
    private const array STATUS_TONES = ['open' => 'gray', 'active' => 'amber', 'done' => 'green', 'closed' => 'gray'];

    public function __construct(
        private readonly WorkQueries $queries,
        private readonly ManageTasks $tasks,
        private readonly TaskHistory $history,
        private readonly People $people,
        private readonly StaffCheck $staff,
        private readonly AuditActor $actor,
        private readonly TenantClock $clock,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/tasks/{id}', name: self::ROUTE, requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function show(string $id, Request $request): Response
    {
        $this->denyUnlessStaff();
        $task = $this->detail($id);
        $edit = $request->query->getString('edit');

        return $this->page($task, \in_array($edit, self::EDITABLE, true) ? $edit : null, [], [], $request->query->has('add'));
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

        return $this->page($this->detail($id), 'title', ['title' => $title], ['title' => $error], false, Response::HTTP_UNPROCESSABLE_ENTITY);
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
     * Adds a subtask by its title, in the section of its task, and comes back
     * with the line ready for the next one. An empty title adds nothing.
     */
    #[Route('/tasks/{id}/subtasks', name: 'app_task_subtasks', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function subtasks(string $id, Request $request): RedirectResponse
    {
        $title = trim($request->request->getString('title'));
        $this->save($id, $request, function () use ($id, $title): void {
            if ('' === $title) {
                return;
            }
            $task = $this->detail($id)->task;
            $this->tasks->create($task->projectId, $title, $task->sectionId, $id);
        });

        return $this->redirectToRoute(self::ROUTE, ['id' => $id, 'add' => 1, '_fragment' => 'add-subtask'], Response::HTTP_SEE_OTHER);
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
    private function page(TaskDetail $task, ?string $edit, array $values, array $errors, bool $adding, int $status = Response::HTTP_OK): Response
    {
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

        return $this->render('work/task/show.html.twig', [
            'task' => $task,
            'edit' => $edit,
            'values' => $values + ['title' => $row->title, 'description' => $task->description],
            'errors' => array_filter($errors, \is_string(...)),
            'adding' => $adding,
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
            'timeline' => array_map($this->timelineItem(...), array_reverse($this->history->of($row->id))),
            'createdAt' => $task->createdAt->format($this->translator->trans('format.datetime')),
            'dueValue' => $row->dueOn?->format('Y-m-d') ?? '',
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
     * One step of the activity: who did it, and what changed in words.
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
                [] !== $entry->fields => 'pencil',
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

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
