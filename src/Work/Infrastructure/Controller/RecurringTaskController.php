<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Controller;

use Foresi\Shared\Application\Field\FieldRefused;
use Foresi\Shared\Application\View\ListSlot;
use Foresi\Shared\Domain\Schedule\PeriodText;
use Foresi\Shared\Domain\Schedule\RecurrenceEnd;
use Foresi\Shared\Infrastructure\View\FilterSheet;
use Foresi\Ui\Contract\WorkingState;
use Foresi\Ui\Field\CustomFieldForm;
use Foresi\Ui\Field\CustomFieldSubmission;
use Foresi\Ui\View\ListTable;
use Foresi\Work\Application\Api\CreateRecurringTaskInput;
use Foresi\Work\Application\Api\UpdateRecurringTaskInput;
use Foresi\Work\Application\LabelBadge;
use Foresi\Work\Application\ManageRecurringTasks;
use Foresi\Work\Application\Projects;
use Foresi\Work\Application\View\RecurringTaskLists;
use Foresi\Work\Application\WorkQueries;
use Foresi\Work\Application\WorkRefused;
use Foresi\Work\Application\WorkVisibility;
use Foresi\Work\Contract\Field\TaskExtension;
use Foresi\Work\Domain\Duration;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\RecurringTask;
use Foresi\Work\Domain\RecurringTaskState;
use Foresi\Work\Domain\Task;
use Foresi\Work\Infrastructure\Api\RecurringTaskJson;
use Foresi\Work\Infrastructure\Column\RecurringTaskColumns;
use Foresi\Work\Infrastructure\Column\RecurringTaskRow;
use Foresi\Work\Infrastructure\Twig\TimeFunctions;
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
 * The recurring tasks of the projects the person sees (plan 37.4): a list with views
 * (docs/design/list-pages.md), a page of each with its actions in the header (pause or
 * resume, end) and its next tasks, each of which can be skipped, as a planned item's
 * occurrence is. A new one and a change open in a dialog with the whole form, as the
 * recurring invoices' form holds the template and its schedule together. A project's
 * "⋯" starts one in that project. Only the people of the organisation reach them.
 */
final class RecurringTaskController extends AbstractController
{
    public const string ROUTE = 'app_work_recurring_tasks';
    public const string SHOW = 'app_work_recurring_task';
    public const string TOKEN = 'work-recurring-task';
    private const array UNITS = ['day', 'week', 'month', 'year'];

    public function __construct(
        private readonly ManageRecurringTasks $recurring,
        private readonly WorkQueries $queries,
        private readonly Projects $projects,
        private readonly WorkVisibility $visibility,
        private readonly RecurringTaskLists $lists,
        private readonly ListTable $listTable,
        private readonly FilterSheet $filterSheet,
        private readonly CustomFieldForm $customFields,
        private readonly TimeFunctions $duration,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/recurring-tasks', name: self::ROUTE, methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessStaff();
        $slot = ListSlot::first($request->query->all());
        $list = $this->lists->list();
        $built = $this->listTable->built($slot, $list);
        if (null !== $built) {
            return $this->redirectToRoute(self::ROUTE, $built, Response::HTTP_SEE_OTHER);
        }
        $records = array_map($this->rowOf(...), $this->recurring->all());
        $page = $this->listTable->of($slot, $list, RecurringTaskColumns::LIST, self::ROUTE, 'recurring-tasks', $records, $this->row(...), static fn (RecurringTaskRow $row, string $field): mixed => $row->value($field), static fn (RecurringTaskRow $row): string => $row->title.' '.$row->projectName, $this->translator->trans('work.recurring.search'));

        return $this->render('@Work/recurring/index.html.twig', [
            'recurring' => $page->with($this->filterSheet->of($list, $page->shown, $page->href, pinned: $page->pinned, slot: $slot)),
        ]);
    }

    #[Route('/recurring-tasks/new', name: 'app_work_recurring_task_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyUnlessStaff();
        $projectId = $request->isMethod('POST') ? $request->request->getString('project') : $request->query->getString('project');
        $project = '' === $projectId ? null : $this->project($projectId);
        $values = $this->defaults($project);
        $errors = [];
        $custom = null;
        // The first step of a new one from the list only picks its project.
        if ($request->isMethod('POST') && $project instanceof Project && 'project' !== $request->request->getString('step')) {
            $this->checkToken($request);
            [$values, $errors] = $this->read($request, $values);
            $custom = $this->customFields->read(TaskExtension::RECORD, $request, true);
            if ([] === $errors && $custom->valid()) {
                try {
                    $recurring = $this->recurring->create($this->createInput($project, $values), $custom->parsed);
                    $this->addFlash('success', $this->translator->trans('work.recurring.created', ['title' => $recurring->title()]));

                    return $this->redirectToRoute(self::SHOW, ['id' => $recurring->id()], Response::HTTP_SEE_OTHER);
                } catch (WorkRefused $refused) {
                    $errors[$this->fieldOf($refused)] = $this->translator->trans('work.refused.'.$refused->reason);
                } catch (FieldRefused $refused) {
                    $custom = $custom->refused($refused->key, $this->translator->trans($refused->translationKey));
                }
            }
        }

        return $this->form($project, null, $values, $errors, $custom, $custom->values ?? $this->customFields->values(TaskExtension::RECORD, null));
    }

    #[Route('/recurring-tasks/{id}', name: self::SHOW, requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function show(string $id): Response
    {
        $recurring = $this->find($id);
        $template = $recurring->template();
        $recurrence = $template->recurrence;
        $labels = [];
        foreach ($this->queries->labels() as $label) {
            if (\in_array($label->id, $recurring->labelIds(), true)) {
                $labels[] = $label;
            }
        }
        $people = [];
        foreach ($this->queries->projectPeople($recurring->project()->id()) ?? [] as $person) {
            $people[$person->id] = $person->name;
        }
        $watchers = array_values(array_filter(array_map(static fn (string $id): ?string => $people[$id] ?? null, $recurring->watcherIds()), \is_string(...)));
        $date = fn (?\DateTimeImmutable $day): ?string => $day?->format($this->translator->trans('format.date'));

        return $this->render('@Work/recurring/show.html.twig', [
            'recurring' => $recurring,
            'state' => $recurring->state()->value,
            'facts' => [
                ['label' => $this->translator->trans('work.recurring.field.project'), 'value' => $recurring->project()->name(), 'href' => $this->generateUrl(ProjectController::PROJECT_ROUTE, ['id' => $recurring->project()->id()])],
                ['label' => $this->translator->trans('work.recurring.field.schedule'), 'value' => $this->scheduleText($recurring)],
                ['label' => $this->translator->trans('work.recurring.field.next'), 'value' => $date($recurring->nextOccurrence())],
                ['label' => $this->translator->trans('work.recurring.field.created_count'), 'value' => (string) $recurring->createdCount()],
            ],
            'task' => [
                ['label' => $this->translator->trans('work.task.field.title'), 'value' => $recurring->title()],
                ['label' => $this->translator->trans('work.task.field.section'), 'value' => $recurring->section()?->name()],
                ['label' => $this->translator->trans('work.task.field.responsible'), 'value' => $recurring->responsible()?->user()->displayName()],
                ['label' => $this->translator->trans('work.recurring.field.priority'), 'value' => $this->translator->trans('work.priority.'.$recurring->priority()->value)],
                ['label' => $this->translator->trans('work.recurring.field.estimate'), 'value' => null === $recurring->estimateMinutes() ? null : $this->duration->duration($recurring->estimateMinutes())],
                ['label' => $this->translator->trans('work.recurring.field.due'), 'value' => null === $template->dueDaysAfter ? $this->translator->trans('work.recurring.due.period') : $this->translator->trans('work.recurring.due.days_text', ['days' => $template->dueDaysAfter])],
                ['label' => $this->translator->trans('work.recurring.field.create_days_before'), 'value' => 0 === $template->createDaysBefore ? $this->translator->trans('work.recurring.lead.on_the_day') : $this->translator->trans('work.recurring.lead.days_text', ['days' => $template->createDaysBefore])],
                ['label' => $this->translator->trans('work.recurring.field.watchers'), 'value' => [] === $watchers ? null : implode(', ', $watchers)],
            ],
            'labels' => $labels,
            'private' => $recurring->isPrivate(),
            'description' => $recurring->description(),
            'subtasks' => $recurring->subtasks(),
            'next' => array_map(fn (array $next): array => $next + ['date' => $date(new \DateTimeImmutable($next['on'])), 'createDate' => $date(new \DateTimeImmutable($next['create_on'])), 'dueDate' => $date(new \DateTimeImmutable($next['due_on']))], RecurringTaskJson::occurrences($recurring, ManageRecurringTasks::PREVIEW)),
            'skipped' => array_map(static fn (string $day): ?string => $date(new \DateTimeImmutable($day)), array_values(array_filter($recurring->skippedOn(), static fn (string $day): bool => $day >= new \DateTimeImmutable('today')->format('Y-m-d')))),
            'tasks' => array_map(fn (Task $task): array => ['id' => $task->id(), 'title' => $task->title(), 'on' => $date($task->occurrenceOn()), 'done' => $task->isCompleted()], $this->recurring->tasks($recurring)),
            'failure' => null === $recurring->lastFailureCode() ? null : $this->translator->trans('work.recurring.failure.'.$recurring->lastFailureCode()),
            'endsSchedule' => RecurrenceEnd::Never !== $recurrence->end(),
        ]);
    }

    #[Route('/recurring-tasks/{id}/edit', name: 'app_work_recurring_task_edit', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $recurring = $this->find($id);
        if (RecurringTaskState::Ended === $recurring->state()) {
            return $this->redirectToRoute(self::SHOW, ['id' => $id], Response::HTTP_SEE_OTHER);
        }
        $values = $this->valuesOf($recurring);
        $errors = [];
        $custom = null;
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            [$values, $errors] = $this->read($request, $values);
            $custom = $this->customFields->read(TaskExtension::RECORD, $request, true);
            if ([] === $errors && $custom->valid()) {
                try {
                    $this->recurring->update($this->updateInput($id, $values), $custom->parsed);
                    $this->addFlash('success', $this->translator->trans('work.recurring.saved'));

                    return $this->redirectToRoute(self::SHOW, ['id' => $id], Response::HTTP_SEE_OTHER);
                } catch (WorkRefused $refused) {
                    $errors[$this->fieldOf($refused)] = $this->translator->trans('work.refused.'.$refused->reason);
                } catch (FieldRefused $refused) {
                    $custom = $custom->refused($refused->key, $this->translator->trans($refused->translationKey));
                }
            }
        }

        return $this->form($recurring->project(), $recurring, $values, $errors, $custom, $custom->values ?? $this->customFields->valuesFrom(TaskExtension::RECORD, $recurring->customFields()));
    }

    #[Route('/recurring-tasks/{id}/pause', name: 'app_work_recurring_task_pause', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function pause(string $id, Request $request): RedirectResponse
    {
        return $this->act($id, $request, fn (): RecurringTask => $this->recurring->pause($id), 'work.recurring.paused');
    }

    #[Route('/recurring-tasks/{id}/resume', name: 'app_work_recurring_task_resume', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function resume(string $id, Request $request): RedirectResponse
    {
        return $this->act($id, $request, fn (): array => $this->recurring->resume($id), 'work.recurring.resumed');
    }

    #[Route('/recurring-tasks/{id}/end', name: 'app_work_recurring_task_end', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function end(string $id, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->act($id, $request, fn (): RecurringTask => $this->recurring->end($id), 'work.recurring.ended');
        }

        return $this->render('@Work/recurring/end.html.twig', ['recurring' => $this->find($id)]);
    }

    #[Route('/recurring-tasks/{id}/skip', name: 'app_work_recurring_task_skip', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function skip(string $id, Request $request): RedirectResponse
    {
        $on = $request->request->getString('on');

        return $this->act($id, $request, fn (): RecurringTask => $this->recurring->skip($id, $on), 'work.recurring.skipped');
    }

    private function act(string $id, Request $request, \Closure $action, string $done): RedirectResponse
    {
        $this->checkToken($request);
        try {
            $action();
            $this->addFlash('success', $this->translator->trans($done));
        } catch (WorkRefused $refused) {
            if ('unknown' === $refused->reason) {
                throw new NotFoundHttpException($refused->getMessage(), $refused);
            }
            if ('not_allowed' === $refused->reason) {
                throw new AccessDeniedHttpException($refused->getMessage(), $refused);
            }
            $this->addFlash('error', $this->translator->trans('work.refused.'.$refused->reason));
        }

        return $this->redirectToRoute(self::SHOW, ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param array<string, mixed>               $values
     * @param array<string, string>              $errors
     * @param array<string, string|list<string>> $customValues
     */
    private function form(?Project $project, ?RecurringTask $recurring, array $values, array $errors, ?CustomFieldSubmission $custom, array $customValues): Response
    {
        $sections = ['' => $this->translator->trans('work.list.no_section')];
        $people = ['' => $this->translator->trans('work.recurring.nobody')];
        $watchers = [];
        if ($project instanceof Project) {
            foreach ($this->projects->sections($project) as $section) {
                if ($this->visibility->canSeeSection($section)) {
                    $sections[$section->id()] = $section->name();
                }
            }
            foreach ($this->queries->projectPeople($project->id()) ?? [] as $person) {
                $people[$person->id] = $person->name;
                $watchers[$person->id] = $person->name;
            }
        }
        $projects = [];
        foreach ($this->queries->taskableProjects(limit: 200) as $row) {
            $projects[$row->id] = $row->name;
        }
        $invalid = [] !== $errors || ($custom instanceof CustomFieldSubmission && !$custom->valid());

        return $this->render('@Work/recurring/form.html.twig', [
            'project' => $project,
            'recurring' => $recurring,
            'values' => $values,
            'errors' => $errors,
            'customValues' => $customValues,
            'customErrors' => $custom->errors ?? [],
            'projects' => $projects,
            'sections' => $sections,
            'people' => $people,
            'watchers' => $watchers,
            'labels' => array_map(static fn (LabelBadge $label): array => ['id' => $label->id, 'name' => $label->name], $this->queries->labels()),
            'priorities' => array_combine(['none', 'low', 'medium', 'high', 'urgent'], array_map(fn (string $priority): string => $this->translator->trans('work.priority.'.$priority), ['none', 'low', 'medium', 'high', 'urgent'])),
            'units' => array_combine(self::UNITS, array_map(fn (string $unit): string => $this->translator->trans('work.recurring.unit.'.$unit), self::UNITS)),
            'ends' => ['never' => $this->translator->trans('work.recurring.end.never'), 'on_date' => $this->translator->trans('work.recurring.end.on_date'), 'after_count' => $this->translator->trans('work.recurring.end.after_count')],
            'dues' => ['period' => $this->translator->trans('work.recurring.due.period'), 'days' => $this->translator->trans('work.recurring.due.days')],
            'variables' => array_map(fn (string $token): array => ['token' => $token, 'label' => $this->translator->trans('work.recurring.variable.'.str_replace('/', '_', trim($token, '{}')))], PeriodText::TOKENS),
        ], new Response(status: $invalid ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(?Project $project): array
    {
        return [
            'project' => $project?->id() ?? '', 'title' => '', 'description' => '', 'section' => '', 'responsible' => '', 'priority' => 'none', 'estimate' => '',
            'labels' => [], 'watchers' => [], 'private' => false, 'subtasks' => '',
            'first_on' => new \DateTimeImmutable('monday next week')->format('Y-m-d'), 'repeat' => 'week', 'every' => '1', 'last_day' => false,
            'end' => 'never', 'ends_on' => '', 'ends_after' => '', 'create_days_before' => '0', 'due' => 'period', 'due_days' => '0',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function valuesOf(RecurringTask $recurring): array
    {
        $recurrence = $recurring->recurrence();

        return [
            'project' => $recurring->project()->id(), 'title' => $recurring->title(), 'description' => $recurring->description(),
            'section' => $recurring->section()?->id() ?? '', 'responsible' => $recurring->responsible()?->id() ?? '', 'priority' => $recurring->priority()->value,
            'estimate' => null === $recurring->estimateMinutes() ? '' : $this->duration->duration($recurring->estimateMinutes()),
            'labels' => $recurring->labelIds(), 'watchers' => $recurring->watcherIds(), 'private' => $recurring->isPrivate(), 'subtasks' => implode("\n", $recurring->subtasks()),
            'first_on' => $recurrence->firstIssueOn()->format('Y-m-d'), 'repeat' => $recurrence->unit()->value, 'every' => (string) $recurrence->every(), 'last_day' => $recurrence->onLastDayOfMonth(),
            'end' => $recurrence->end()->value, 'ends_on' => $recurrence->endOn()?->format('Y-m-d') ?? '', 'ends_after' => null === $recurrence->endAfter() ? '' : (string) $recurrence->endAfter(),
            'create_days_before' => (string) $recurring->createDaysBefore(), 'due' => null === $recurring->dueDaysAfter() ? 'period' : 'days', 'due_days' => (string) ($recurring->dueDaysAfter() ?? 0),
        ];
    }

    /**
     * The posted form, with the errors of what cannot be read.
     *
     * @param array<string, mixed> $values
     *
     * @return array{array<string, mixed>, array<string, string>}
     */
    private function read(Request $request, array $values): array
    {
        $posted = $request->request;
        $text = static fn (string $name): string => trim($posted->getString($name));
        $list = static fn (string $name): array => array_values(array_filter($posted->all($name), \is_string(...)));
        $values = [
            'title' => $text('title'), 'description' => $posted->getString('description'), 'section' => $text('section'), 'responsible' => $text('responsible'),
            'priority' => $text('priority'), 'estimate' => $text('estimate'), 'labels' => $list('labels'), 'watchers' => $list('watchers'), 'private' => $posted->getBoolean('private'),
            'subtasks' => $posted->getString('subtasks'), 'first_on' => $text('first_on'), 'repeat' => $text('repeat'), 'every' => $text('every'), 'last_day' => $posted->getBoolean('last_day'),
            'end' => $text('end'), 'ends_on' => $text('ends_on'), 'ends_after' => $text('ends_after'), 'create_days_before' => $text('create_days_before'), 'due' => $text('due'), 'due_days' => $text('due_days'),
        ] + $values;
        $errors = [];
        if ('' === $values['title']) {
            $errors['title'] = $this->translator->trans('work.task.error.title_required');
        }
        if ('' !== $values['estimate']) {
            try {
                Duration::parse($values['estimate'], Task::MAX_ESTIMATE_MINUTES);
            } catch (\DomainException) {
                $errors['estimate'] = $this->translator->trans('work.refused.estimate');
            }
        }
        if (1 !== preg_match('/^\d{1,3}$/', $values['every'])) {
            $errors['every'] = $this->translator->trans('work.refused.schedule');
        }
        if (1 !== preg_match('/^\d{1,3}$/', $values['create_days_before'])) {
            $errors['create_days_before'] = $this->translator->trans('work.refused.create_days_before');
        }
        if ('days' === $values['due'] && 1 !== preg_match('/^\d{1,3}$/', $values['due_days'])) {
            $errors['due_days'] = $this->translator->trans('work.refused.due_days_after');
        }
        if ('after_count' === $values['end'] && 1 !== preg_match('/^\d{1,3}$/', $values['ends_after'])) {
            $errors['ends_after'] = $this->translator->trans('work.refused.schedule');
        }

        return [$values, $errors];
    }

    /**
     * @param array<string, mixed> $values
     */
    private function createInput(Project $project, array $values): CreateRecurringTaskInput
    {
        $f = $this->fields($values);

        return new CreateRecurringTaskInput($project->id(), $f['title'], $f['firstOn'], $f['repeat'], $f['every'], $f['lastDayOfMonth'], $f['end'], $f['endsOn'], $f['endsAfter'], $f['createDaysBefore'], $f['dueDaysAfter'], $f['description'], $f['sectionId'], $f['responsibleId'], $f['priority'], $f['private'], $f['estimateMinutes'], $f['labelIds'], $f['watcherIds'], $f['subtasks']);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function updateInput(string $id, array $values): UpdateRecurringTaskInput
    {
        $f = $this->fields($values);

        return new UpdateRecurringTaskInput($id, $f['title'], $f['firstOn'], $f['repeat'], $f['every'], $f['lastDayOfMonth'], $f['end'], $f['endsOn'], $f['endsAfter'], $f['createDaysBefore'], $f['dueDaysAfter'], $f['description'], $f['sectionId'], $f['responsibleId'], $f['priority'], $f['private'], $f['estimateMinutes'], $f['labelIds'], $f['watcherIds'], $f['subtasks']);
    }

    /**
     * The form's values as the inputs take them.
     *
     * @param array<string, mixed> $v
     *
     * @return array{title: string, firstOn: string, repeat: string, every: int, lastDayOfMonth: bool, end: string, endsOn: ?string, endsAfter: ?int, createDaysBefore: int, dueDaysAfter: ?int, description: string, sectionId: ?string, responsibleId: ?string, priority: string, private: bool, estimateMinutes: ?int, labelIds: list<string>, watcherIds: list<string>, subtasks: list<string>}
     */
    private function fields(array $v): array
    {
        $string = static fn (string $key): string => \is_string($v[$key] ?? null) ? $v[$key] : '';
        $strings = static fn (string $key): array => \is_array($v[$key] ?? null) ? array_values(array_filter($v[$key], \is_string(...))) : [];
        $optional = static fn (string $key): ?string => '' === $string($key) ? null : $string($key);
        $end = $string('end');

        return [
            'title' => $string('title'),
            'firstOn' => $string('first_on'),
            'repeat' => $string('repeat'),
            'every' => (int) $string('every'),
            'lastDayOfMonth' => true === ($v['last_day'] ?? false) && \in_array($string('repeat'), ['month', 'year'], true),
            'end' => $end,
            'endsOn' => 'on_date' === $end ? $optional('ends_on') : null,
            'endsAfter' => 'after_count' === $end ? (int) $string('ends_after') : null,
            'createDaysBefore' => (int) $string('create_days_before'),
            'dueDaysAfter' => 'days' === $string('due') ? (int) $string('due_days') : null,
            'description' => $string('description'),
            'sectionId' => $optional('section'),
            'responsibleId' => $optional('responsible'),
            'priority' => $string('priority'),
            'private' => true === ($v['private'] ?? false),
            'estimateMinutes' => '' === $string('estimate') ? null : Duration::parse($string('estimate'), Task::MAX_ESTIMATE_MINUTES)->minutes,
            'labelIds' => $strings('labels'),
            'watcherIds' => $strings('watchers'),
            'subtasks' => array_values(array_filter(array_map(trim(...), false === ($lines = preg_split('/\R/u', $string('subtasks'))) ? [] : $lines), static fn (string $line): bool => '' !== $line)),
        ];
    }

    private function fieldOf(WorkRefused $refused): string
    {
        return match ($refused->reason) {
            'name' => 'title',
            'responsible' => 'responsible',
            'watcher' => 'watchers',
            'estimate' => 'estimate',
            'schedule', 'date', 'no_date' => 'first_on',
            'create_days_before' => 'create_days_before',
            'due_days_after' => 'due_days',
            'subtasks' => 'subtasks',
            default => 'form',
        };
    }

    private function scheduleText(RecurringTask $recurring): string
    {
        $recurrence = $recurring->recurrence();
        $text = $this->translator->trans('work.recurring.every', ['count' => $recurrence->every(), 'unit' => $recurrence->unit()->value]);
        if ($recurrence->onLastDayOfMonth()) {
            $text .= ', '.$this->translator->trans('work.recurring.last_day');
        }

        return $text.match ($recurrence->end()) {
            RecurrenceEnd::Never => '',
            RecurrenceEnd::OnDate => ', '.$this->translator->trans('work.recurring.until', ['date' => $recurrence->endOn()?->format($this->translator->trans('format.date')) ?? '']),
            RecurrenceEnd::AfterCount => ', '.$this->translator->trans('work.recurring.times', ['count' => $recurrence->endAfter() ?? 0]),
        };
    }

    private function rowOf(RecurringTask $recurring): RecurringTaskRow
    {
        return new RecurringTaskRow($recurring->id(), $recurring->title(), $recurring->project()->id(), $recurring->project()->name(), $recurring->state(), $this->scheduleText($recurring), $recurring->nextOccurrence(), $recurring->createdCount(), null !== $recurring->lastFailureCode());
    }

    /**
     * @return array<string, mixed>
     */
    private function row(RecurringTaskRow $row, WorkingState $working): array
    {
        return ['id' => $row->id, 'label' => $row->title, 'cells' => $working->display->cells($row), 'href' => $this->generateUrl(self::SHOW, ['id' => $row->id])];
    }

    private function find(string $id): RecurringTask
    {
        $this->denyUnlessStaff();
        try {
            return $this->recurring->get($id);
        } catch (WorkRefused $refused) {
            throw new NotFoundHttpException($refused->getMessage(), $refused);
        }
    }

    private function project(string $id): Project
    {
        $project = $this->projects->project($id);
        if (!$project instanceof Project || !$this->visibility->canSeeProject($project) || $project->isArchived()) {
            throw new NotFoundHttpException('No such project.');
        }

        return $project;
    }

    private function denyUnlessStaff(): void
    {
        if (!$this->visibility->canManage()) {
            throw new AccessDeniedHttpException('Only the people of the organisation keep recurring tasks.');
        }
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
