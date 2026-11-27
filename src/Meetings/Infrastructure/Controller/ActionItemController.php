<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Infrastructure\Controller;

use Foresi\Meetings\Application\ActionItemReads;
use Foresi\Meetings\Application\ActionItemWrites;
use Foresi\Meetings\Application\MeetingReads;
use Foresi\Meetings\Application\MeetingsRefused;
use Foresi\Meetings\Application\MeetingView;
use Foresi\Meetings\Application\MeetingVisibility;
use Foresi\Meetings\Application\SeriesReads;
use Foresi\Meetings\Domain\Meeting;
use Foresi\Meetings\Domain\MeetingSeries;
use Foresi\Meetings\Domain\SeriesVisibility;
use Foresi\Meetings\Infrastructure\Screen\TaskPills;
use Foresi\Meetings\Infrastructure\Target\ProjectMeetingTarget;
use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Contract\Refused;
use Foresi\Shared\Domain\Id;
use Foresi\Ui\Component\MarkdownEditor;
use Foresi\Ui\Contract\Dialog\DialogRequest;
use Foresi\Work\Application\ProjectRow;
use Foresi\Work\Application\WorkQueries;
use Foresi\Work\Domain\ProjectVisibility;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tasks from a meeting's minutes (spec, Create task from the minutes), as in
 * Linear's and ClickUp's "create from selection": the Markdown editor's Create
 * task opens a dialog with the selection as the title, the project (the
 * series' default, then a project the meeting links, then the one the person
 * last added a task to), the responsible person (the attendees first, only
 * people who see the project), the due day and, in a series for its members,
 * Private (on for a project the whole firm sees). Create answers the task's id
 * to the editor, which puts the token in place of the selection; the dialog's
 * key makes a second click answer the same task. Also the Preview of the
 * minutes with the pills, and the open tasks of a series.
 */
final class ActionItemController extends AbstractController
{
    public const string ROUTE = 'app_meetings_action_item_new';
    public const string TOKEN = 'meetings_action_item';
    /** The event the editor waits for, with the task's id. */
    public const string EVENT = 'markdown-editor:task-created';

    private const int MAX_PROJECTS = 200;
    private const int MAX_TITLE = 500;

    public function __construct(
        private readonly MeetingReads $reads,
        private readonly SeriesReads $series,
        private readonly ActionItemWrites $writes,
        private readonly ActionItemReads $tasks,
        private readonly MeetingVisibility $visibility,
        private readonly WorkQueries $work,
        private readonly TaskPills $pills,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/meetings/{id}/action-items/new', name: self::ROUTE, requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function new(string $id, Request $request): Response
    {
        $view = $this->view($id);
        $meeting = $view->meeting;
        if ($meeting->isTrashed()) {
            throw new NotFoundHttpException('The meeting is in the trash.');
        }
        $post = $request->isMethod('POST');
        $input = $post ? $request->request : $request->query;
        $projects = $this->projects($view);
        $values = [
            'key' => $post ? $input->getString('key') : Id::generate(),
            'title' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', $input->getString('title'))), 0, self::MAX_TITLE),
            'project' => $input->getString('project'),
            'responsible' => $input->getString('responsible'),
            'due' => $input->getString('due'),
            'private' => $input->getBoolean('private'),
        ];
        if (!isset($projects[$values['project']])) {
            $values['project'] = (string) array_key_first($projects);
        }
        $offersPrivate = $meeting->series() instanceof MeetingSeries && SeriesVisibility::Members === $meeting->series()->visibility();
        $creating = $post && 'create' === $request->request->getString('_action');
        if (!$creating) {
            // A C-level task does not show up for the whole firm by accident (spec, Private).
            $values['private'] = $offersPrivate && ProjectVisibility::Organisation === ($projects[$values['project']] ?? null)?->visibility;
        }
        $people = $this->people($view, '' === $values['project'] ? null : $values['project']);
        if (!isset($people[$values['responsible']])) {
            $values['responsible'] = '';
        }
        $errors = [];
        if ($creating) {
            $this->checkToken($request);
            $errors = $this->checked($values, $projects);
            if ([] === $errors) {
                try {
                    $item = $this->writes->fromEditor(
                        $meeting->id(),
                        $values['key'],
                        $values['title'],
                        $values['project'],
                        '' === $values['responsible'] ? null : $values['responsible'],
                        '' === $values['due'] ? null : new \DateTimeImmutable($values['due']),
                        $offersPrivate && $values['private'],
                    );
                    if (DialogRequest::is($request)) {
                        return new JsonResponse(['event' => self::EVENT, 'detail' => ['taskId' => $item->taskId()], 'message' => $this->translator->trans('meetings.action_item.created', ['title' => $values['title']])]);
                    }
                    $this->addFlash('success', $this->translator->trans('meetings.action_item.created', ['title' => $values['title']]));

                    return $this->redirectToRoute(MeetingController::MEETING_ROUTE, ['id' => $meeting->id()], Response::HTTP_SEE_OTHER);
                } catch (MeetingsRefused $refused) {
                    if (RefusalKind::Invalid !== $refused->kind()) {
                        throw new NotFoundHttpException($refused->getMessage(), $refused);
                    }
                    $errors['form'] = $refused->getMessage();
                } catch (Refused $refused) {
                    $errors[match ($refused->reason()) {
                        'name' => 'title',
                        'responsible' => 'responsible',
                        'unknown', 'archived', 'not_allowed' => 'project',
                        default => 'form',
                    }] = $this->translator->trans('work.refused.'.$refused->reason());
                }
            }
        }

        return $this->render('@Meetings/action_items/new.html.twig', [
            'meeting' => $meeting,
            'values' => $values,
            'errors' => $errors,
            'projects' => array_map(static fn (ProjectRow $row): string => $row->name, $projects),
            'people' => $people,
            'offersPrivate' => $offersPrivate,
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * The Preview tab of the minutes: the Markdown as the meeting's page shows it, with the pills.
     */
    #[Route('/meetings/{id}/minutes/preview', name: 'app_meetings_minutes_preview', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function preview(string $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(MarkdownEditor::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $this->view($id);
        $text = $request->request->getString('text');
        if (mb_strlen($text) > Meeting::MAX_MINUTES) {
            return new Response('', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new Response($this->pills->minutes($text, $this->tasks->referred($text)), Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * Every open task of a series' meetings, by meeting, newest first: where "All
     * open tasks of this series" leads, the aged-out ones included.
     */
    #[Route('/meetings/series/{id}/open-tasks', name: 'app_meetings_series_open_tasks', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function openTasks(string $id): Response
    {
        if (!$this->visibility->mayUse()) {
            throw new AccessDeniedHttpException('Only the owners and members of the organisation keep its meetings.');
        }
        try {
            $series = $this->series->one($id);
        } catch (MeetingsRefused $refused) {
            throw new NotFoundHttpException($refused->getMessage(), $refused);
        }

        return $this->render('@Meetings/series/open_tasks.html.twig', [
            'series' => $series,
            'groups' => $this->tasks->openOfSeries($series),
            'today' => $this->pills->today(),
        ]);
    }

    /**
     * The projects a task of the meeting can go in, the likeliest first: the
     * series' default, the projects the meeting links, then those the person last
     * added a task to; only the active ones the person may add a task to.
     *
     * @return array<string, ProjectRow> by id
     */
    private function projects(MeetingView $view): array
    {
        $taskable = [];
        foreach ($this->work->taskableProjects(limit: self::MAX_PROJECTS) as $row) {
            $taskable[$row->id] = $row;
        }
        $first = [];
        $default = $view->meeting->series()?->defaultProjectId();
        foreach ([$default, ...array_map(static fn (array $link): ?string => ProjectMeetingTarget::KIND === $link['kind'] ? $link['id'] : null, $view->links)] as $projectId) {
            if (null !== $projectId && isset($taskable[$projectId])) {
                $first[$projectId] = $taskable[$projectId];
            }
        }

        return $first + $taskable;
    }

    /**
     * Who may be responsible: nobody, the attendees who see the project, then the others who do.
     *
     * @return array<string, string> names by membership id
     */
    private function people(MeetingView $view, ?string $projectId): array
    {
        $seeing = [];
        foreach (null === $projectId ? [] : ($this->work->projectPeople($projectId) ?? []) as $person) {
            $seeing[$person->id] = $person->name;
        }
        $people = ['' => $this->translator->trans('meetings.task.nobody')];
        foreach ($view->attendees as $attendee) {
            if (isset($seeing[$attendee['id']])) {
                $people[$attendee['id']] = $seeing[$attendee['id']];
            }
        }

        return $people + $seeing;
    }

    /**
     * @param array{key: string, title: string, project: string, responsible: string, due: string, private: bool} $values
     * @param array<string, ProjectRow>                                                                           $projects
     *
     * @return array<string, string>
     */
    private function checked(array $values, array $projects): array
    {
        $errors = [];
        if ('' === $values['title']) {
            $errors['title'] = $this->translator->trans('work.task.error.title_required');
        }
        if (!isset($projects[$values['project']])) {
            $errors['project'] = $this->translator->trans('work.tasks.new.project_required');
        }
        if ('' !== $values['due'] && (false === ($due = \DateTimeImmutable::createFromFormat('!Y-m-d', $values['due'])) || $due->format('Y-m-d') !== $values['due'])) {
            $errors['due'] = $this->translator->trans('work.tasks.new.due_invalid');
        }

        return $errors;
    }

    private function view(string $id): MeetingView
    {
        try {
            return $this->reads->view($id);
        } catch (MeetingsRefused $refused) {
            if (RefusalKind::Forbidden === $refused->kind() && 'forbidden' === $refused->reason()) {
                throw new AccessDeniedHttpException($refused->getMessage(), $refused);
            }
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
