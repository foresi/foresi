<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Controller;

use App\Tenancy\Application\TenantClock;
use App\Work\Application\ManageTime;
use App\Work\Application\TimeEntryRow;
use App\Work\Application\TimeQueries;
use App\Work\Application\TimeTarget;
use App\Work\Application\WorkQueries;
use App\Work\Application\WorkRefused;
use App\Work\Domain\Duration;
use App\Work\Domain\Task;
use App\Work\Domain\TimeEntry;
use App\Work\Infrastructure\Twig\TimeFunctions;
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
 * Time on a task, or on a project without a task: an entry typed into a
 * dialog over the page (a page of its own without JavaScript) with the day,
 * the duration as one types it (Kimai), a note and whether it is billable,
 * the project's default unless changed (Kimai, Toggl); its author or an owner
 * edits it in the same dialog and deletes it from its line.
 *
 * The timer starts from the header of a task and stops from the bar at the
 * top of every page (Freelo, ClickUp); starting it on another task saves the
 * running one. Guests never log time: none of these pages is open to them.
 *
 * From the week of time the same dialog picks the project and, optionally, one
 * of its tasks (Clockify, Harvest timesheet): choosing another project shows
 * its tasks and its billable default, and the dialog starts the timer there as
 * well as logging time. A page that opens the dialog may name itself as the
 * place to go back to.
 */
final class TimeController extends AbstractController
{
    public const string TOKEN = 'work_time';
    /** The refusals told next to their field; any other is told over the form. */
    private const array FIELDS = ['duration' => 'duration', 'time_note' => 'note'];

    public function __construct(
        private readonly ManageTime $time,
        private readonly TimeQueries $queries,
        private readonly TenantClock $clock,
        private readonly TranslatorInterface $translator,
        private readonly TimeFunctions $format,
        private readonly WorkQueries $work,
    ) {
    }

    /**
     * Time anywhere: the project and the task are chosen in the dialog.
     */
    #[Route('/time/new', name: 'app_time_new', methods: ['GET', 'POST'])]
    public function newAnywhere(Request $request): Response
    {
        $return = $this->returnPath($request) ?? $this->generateUrl(TimeWeekController::ROUTE);
        $projects = [];
        foreach ($this->work->projects() as $row) {
            $projects[$row->id] = $row->name;
        }
        $posted = $request->isMethod('POST');
        $project = $posted ? $request->request->getString('project') : $request->query->getString('project');
        $project = isset($projects[$project]) ? $project : '';
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->query->getString('day'));
        $values = [
            'worked_on' => ($day instanceof \DateTimeImmutable ? $day : $this->clock->today())->format('Y-m-d'),
            'duration' => '',
            'note' => '',
            'billable' => $this->billableByDefault($project),
        ];
        $task = '';
        $errors = [];
        $picked = false;
        if ($posted) {
            $this->checkToken($request);
            $values = $this->values($request);
            $task = $request->request->getString('task');
            if ($project !== $request->request->getString('picked_project') || $request->request->has('pick')) {
                // Another project: its tasks to choose from and its billable default, nothing saved yet.
                $values['billable'] = $this->billableByDefault($project);
                $task = '';
                $picked = true;
            }
        }
        $tasks = '' === $project ? [] : $this->taskChoices($project);
        if (!$posted || $picked) {
            $task = (string) array_key_first($tasks);
        }
        if ($posted && !$picked) {
            if ('' === $project) {
                $errors['project'] = $this->translator->trans('work.time.error.project');
            } elseif ([] === $tasks) {
                $errors['project'] = $this->translator->trans('work.refused.not_allowed');
            } elseif (!isset($tasks[$task])) {
                $errors['task'] = $this->translator->trans('work.time.error.task');
            }
            $minutes = null;
            $workedOn = null;
            $timer = $request->request->has('timer');
            if (!$timer) {
                [$workedOn, $minutes, $readErrors] = $this->read($values);
                $errors += $readErrors;
            }
            if ([] === $errors) {
                try {
                    if ($timer) {
                        $this->time->startTimer($project, '' === $task ? null : $task, $values['note'], $values['billable']);
                        $this->addFlash('success', $this->translator->trans('work.time.timer.started'));
                    } elseif (null !== $workedOn && null !== $minutes) {
                        $this->time->log($project, '' === $task ? null : $task, $workedOn, $minutes, note: $values['note'], billable: $values['billable']);
                        $this->addFlash('success', $this->translator->trans('work.time.logged', ['duration' => $this->format->duration($minutes)]));
                    }

                    return $this->redirect($return, Response::HTTP_SEE_OTHER);
                } catch (WorkRefused $refused) {
                    $this->notFoundIfUnknown($refused);
                    $errors = $this->refusal($refused);
                }
            }
        }

        return $this->render('work/time/entry.html.twig', [
            'projectId' => null,
            'projectName' => null,
            'taskId' => null,
            'taskTitle' => null,
            'entry' => null,
            'picker' => [
                'projects' => ['' => $this->translator->trans('work.time.choose_project')] + $projects,
                'project' => $project,
                'tasks' => $tasks,
                'task' => $task,
                'picked' => $picked,
            ],
            'action' => $this->generateUrl('app_time_new', ['return' => $return]),
            'back' => $return,
            'values' => $values,
            'errors' => $errors,
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/tasks/{id}/time/new', name: 'app_task_time_new', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function newOnTask(string $id, Request $request): Response
    {
        return $this->log($this->queries->taskTarget($id) ?? throw new NotFoundHttpException(), $request);
    }

    #[Route('/projects/{id}/time/new', name: 'app_project_time_new', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function newOnProject(string $id, Request $request): Response
    {
        return $this->log($this->queries->projectTarget($id) ?? throw new NotFoundHttpException(), $request);
    }

    /**
     * A running timer is stopped, not edited; an entry one may not change is not found.
     */
    #[Route('/time/{id}/edit', name: 'app_time_edit', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $entry = $this->queries->editable($id);
        if (!$entry instanceof TimeEntryRow || $entry->running || null === $entry->minutes) {
            throw new NotFoundHttpException();
        }
        $shown = $this->format->duration($entry->minutes);
        $values = [
            'worked_on' => $entry->workedOn->format('Y-m-d'),
            'duration' => $shown,
            'note' => $entry->note,
            'billable' => $entry->billable,
        ];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $values = $this->values($request);
            [$workedOn, $minutes, $errors] = $this->read($values);
            if ([] === $errors && null !== $workedOn && null !== $minutes) {
                // The start and the end of a stopped timer stay while its length does.
                $keep = $values['duration'] === $shown && $entry->startedAt instanceof \DateTimeImmutable && $entry->endedAt instanceof \DateTimeImmutable;
                try {
                    $this->time->edit($id, $entry->taskId, $workedOn, $keep ? null : $minutes, $keep ? $entry->startedAt : null, $keep ? $entry->endedAt : null, $values['note'], $values['billable']);
                    $this->addFlash('success', $this->translator->trans('work.time.edited'));
                    $return = $this->returnPath($request);

                    return null === $return ? $this->back($entry->projectId, $entry->taskId) : $this->redirect($return, Response::HTTP_SEE_OTHER);
                } catch (WorkRefused $refused) {
                    $this->notFoundIfUnknown($refused);
                    $errors = $this->refusal($refused);
                }
            }
        }

        return $this->form($entry->projectId, $entry->projectName, $entry->taskId, $entry->taskTitle, $values, $errors, $entry, $this->returnPath($request));
    }

    #[Route('/time/{id}/delete', name: 'app_time_delete', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function delete(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $entry = $this->queries->editable($id) ?? throw new NotFoundHttpException();
        try {
            $this->time->delete($id);
            $this->addFlash('success', $this->translator->trans('work.time.deleted'));
        } catch (WorkRefused $refused) {
            $this->notFoundIfUnknown($refused);
            $this->addFlash('error', $this->translator->trans('work.refused.'.$refused->reason));
        }
        $return = $this->returnPath($request);

        return null === $return ? $this->back($entry->projectId, $entry->taskId) : $this->redirect($return, Response::HTTP_SEE_OTHER);
    }

    /**
     * Starts the timer on the task, from now; the one running elsewhere is saved first.
     */
    #[Route('/tasks/{id}/timer', name: 'app_task_timer_start', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function start(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $target = $this->queries->taskTarget($id) ?? throw new NotFoundHttpException();
        try {
            $this->time->startTimer($target->projectId, $id);
        } catch (WorkRefused $refused) {
            $this->notFoundIfUnknown($refused);
            $this->addFlash('error', $this->translator->trans('work.refused.'.$refused->reason));
        }

        return $this->back($target->projectId, $id, fragment: false);
    }

    /**
     * Stops the timer of the signed-in person and goes back to the page it was
     * stopped on, which tells what was logged.
     */
    #[Route('/timer/stop', name: 'app_timer_stop', methods: ['POST'])]
    public function stop(Request $request): RedirectResponse
    {
        $this->checkToken($request);
        try {
            $stopped = $this->time->stopTimer();
        } catch (WorkRefused $refused) {
            $this->notFoundIfUnknown($refused);
            $this->addFlash('error', $this->translator->trans('work.refused.'.$refused->reason));

            return $this->redirectToRoute(ProjectController::ROUTE, status: Response::HTTP_SEE_OTHER);
        }
        if (!$stopped instanceof TimeEntry) {
            return $this->redirectToRoute(ProjectController::ROUTE, status: Response::HTTP_SEE_OTHER);
        }
        $this->addFlash('success', $this->translator->trans('work.time.stopped', [
            'duration' => $this->format->duration($stopped->minutes() ?? 0),
            'title' => $stopped->task()?->title() ?? $stopped->project()->name(),
        ]));
        $return = $request->request->getString('return');

        return $this->isLocalPath($return)
            ? $this->redirect($return, Response::HTTP_SEE_OTHER)
            : $this->back($stopped->project()->id(), $stopped->task()?->id(), fragment: false);
    }

    /**
     * The time the task should take, typed as a duration; the clear button takes it away.
     */
    #[Route('/tasks/{id}/estimate', name: 'app_task_estimate', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function estimate(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $text = $request->request->has('clear') ? '' : trim($request->request->getString('estimate'));
        try {
            $this->time->setEstimate($id, '' === $text ? null : Duration::parse($text, Task::MAX_ESTIMATE_MINUTES)->minutes);
        } catch (WorkRefused $refused) {
            $this->notFoundIfUnknown($refused);
            $this->addFlash('error', $this->translator->trans('work.refused.'.$refused->reason));
        } catch (\DomainException) {
            $this->addFlash('error', $this->translator->trans('work.refused.estimate'));
        }

        return $this->redirectToRoute(TaskController::ROUTE, ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    private function log(TimeTarget $target, Request $request): Response
    {
        $values = [
            'worked_on' => $this->clock->today()->format('Y-m-d'),
            'duration' => '',
            'note' => '',
            'billable' => $target->billableByDefault,
        ];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $values = $this->values($request);
            [$workedOn, $minutes, $errors] = $this->read($values);
            if ([] === $errors && null !== $workedOn && null !== $minutes) {
                try {
                    $this->time->log($target->projectId, $target->taskId, $workedOn, $minutes, note: $values['note'], billable: $values['billable']);
                    $this->addFlash('success', $this->translator->trans('work.time.logged', ['duration' => $this->format->duration($minutes)]));

                    return $this->back($target->projectId, $target->taskId);
                } catch (WorkRefused $refused) {
                    $this->notFoundIfUnknown($refused);
                    $errors = $this->refusal($refused);
                }
            }
        }

        return $this->form($target->projectId, $target->projectName, $target->taskId, $target->taskTitle, $values, $errors);
    }

    /**
     * @param array{worked_on: string, duration: string, note: string, billable: bool} $values
     * @param array<string, string>                                                    $errors
     */
    private function form(string $projectId, string $projectName, ?string $taskId, ?string $taskTitle, array $values, array $errors, ?TimeEntryRow $entry = null, ?string $return = null): Response
    {
        $query = null === $return ? [] : ['return' => $return];

        return $this->render('work/time/entry.html.twig', [
            'projectId' => $projectId,
            'projectName' => $projectName,
            'taskId' => $taskId,
            'taskTitle' => $taskTitle,
            'entry' => $entry,
            'action' => $entry instanceof TimeEntryRow
                ? $this->generateUrl('app_time_edit', ['id' => $entry->id] + $query)
                : (null === $taskId ? $this->generateUrl('app_project_time_new', ['id' => $projectId]) : $this->generateUrl('app_task_time_new', ['id' => $taskId])),
            'back' => $return ?? $this->backUrl($projectId, $taskId),
            'values' => $values,
            'errors' => $errors,
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * @return array{worked_on: string, duration: string, note: string, billable: bool}
     */
    private function values(Request $request): array
    {
        return [
            'worked_on' => trim($request->request->getString('worked_on')),
            'duration' => trim($request->request->getString('duration')),
            'note' => $request->request->getString('note'),
            'billable' => $request->request->has('billable'),
        ];
    }

    /**
     * The day and the minutes typed, or what is wrong with them.
     *
     * @param array{worked_on: string, duration: string, note: string, billable: bool} $values
     *
     * @return array{?\DateTimeImmutable, ?int, array<string, string>}
     */
    private function read(array $values): array
    {
        $errors = [];
        $workedOn = \DateTimeImmutable::createFromFormat('!Y-m-d', $values['worked_on']);
        if (false === $workedOn || $workedOn->format('Y-m-d') !== $values['worked_on']) {
            $workedOn = null;
            $errors['worked_on'] = $this->translator->trans('work.time.error.worked_on');
        }
        $minutes = null;
        try {
            $minutes = Duration::parse($values['duration'])->minutes;
        } catch (\DomainException) {
            $errors['duration'] = $this->translator->trans('work.refused.duration');
        }

        return [$workedOn, $minutes, $errors];
    }

    /**
     * @return array<string, string>
     */
    private function refusal(WorkRefused $refused): array
    {
        return [self::FIELDS[$refused->reason] ?? 'form' => $this->translator->trans('work.refused.'.$refused->reason)];
    }

    private function back(string $projectId, ?string $taskId, bool $fragment = true): RedirectResponse
    {
        return $this->redirect($this->backUrl($projectId, $taskId, $fragment), Response::HTTP_SEE_OTHER);
    }

    /**
     * The time of the task, or the project for time without a task.
     */
    private function backUrl(string $projectId, ?string $taskId, bool $fragment = true): string
    {
        return null === $taskId
            ? $this->generateUrl(ProjectController::PROJECT_ROUTE, ['id' => $projectId])
            : $this->generateUrl(TaskController::ROUTE, ['id' => $taskId] + ($fragment ? ['_fragment' => 'task-time-title'] : []));
    }

    /**
     * The page that opened the dialog, to go back to: a path of this installation.
     */
    private function returnPath(Request $request): ?string
    {
        $return = $request->query->has('return') ? $request->query->getString('return') : $request->request->getString('return');

        return $this->isLocalPath($return) ? $return : null;
    }

    private function billableByDefault(string $projectId): bool
    {
        if ('' === $projectId) {
            return false;
        }
        $target = $this->queries->projectTarget($projectId) ?? ($this->queries->taskTargets($projectId)[0] ?? null);

        return $target->billableByDefault ?? false;
    }

    /**
     * Where in the project time may go: on the project without a task when it
     * is open as a whole, and on each task seen.
     *
     * @return array<string, string> titles by task id, '' for none
     */
    private function taskChoices(string $projectId): array
    {
        $choices = $this->queries->projectTarget($projectId) instanceof TimeTarget ? ['' => $this->translator->trans('work.time.no_task')] : [];
        foreach ($this->queries->taskTargets($projectId) as $target) {
            $choices[(string) $target->taskId] = (string) $target->taskTitle;
        }

        return $choices;
    }

    /**
     * A path of this installation: no scheme, no host, not protocol-relative.
     */
    private function isLocalPath(string $path): bool
    {
        return str_starts_with($path, '/') && !str_starts_with($path, '//') && !str_contains($path, '\\') && 1 !== preg_match('/[\x00-\x1F]/', $path);
    }

    private function notFoundIfUnknown(WorkRefused $refused): void
    {
        if ('unknown' === $refused->reason) {
            throw new NotFoundHttpException($refused->getMessage(), $refused);
        }
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
