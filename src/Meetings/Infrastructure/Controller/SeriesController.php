<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Infrastructure\Controller;

use Foresi\Meetings\Application\Api\ArchiveSeriesInput;
use Foresi\Meetings\Application\Api\CreateSeriesInput;
use Foresi\Meetings\Application\Api\UpdateSeriesInput;
use Foresi\Meetings\Application\MeetingPeople;
use Foresi\Meetings\Application\MeetingsRefused;
use Foresi\Meetings\Application\MeetingVisibility;
use Foresi\Meetings\Application\SeriesReads;
use Foresi\Meetings\Application\SeriesView;
use Foresi\Meetings\Application\SeriesWrites;
use Foresi\Meetings\Domain\SeriesVisibility;
use Foresi\Shared\Contract\RefusalKind;
use Foresi\Work\Application\WorkQueries;
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
 * The meeting series the person sees: their name, visibility, members, number of
 * meetings and the last one. New series is in the page header and opens a
 * dialog; Edit and Archive are in the menu of a row, for the owners and the
 * members of the series. Without JavaScript the dialog is a page of its own.
 */
final class SeriesController extends AbstractController
{
    public const string ROUTE = 'app_meetings_series';

    public function __construct(
        private readonly SeriesReads $reads,
        private readonly SeriesWrites $writes,
        private readonly MeetingVisibility $visibility,
        private readonly MeetingPeople $people,
        private readonly WorkQueries $work,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/meetings/series', name: self::ROUTE, methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessStaff();
        $archived = '1' === $request->query->getString('archived');
        $label = fn (string $key): string => $this->translator->trans($key);
        $token = MeetingController::TOKEN;

        return $this->render('@Meetings/series/index.html.twig', [
            'archived' => $archived,
            'columns' => [
                ['key' => 'name', 'label' => $label('meetings.series.column.name'), 'truncate' => true],
                ['key' => 'visibility', 'label' => $label('meetings.series.column.visibility'), 'phone' => false],
                ['key' => 'members', 'label' => $label('meetings.series.column.members'), 'truncate' => true, 'phone' => false],
                ['key' => 'meetings', 'label' => $label('meetings.series.column.meetings'), 'align' => 'right'],
                ['key' => 'last', 'label' => $label('meetings.series.column.last'), 'phone' => false],
            ],
            'rows' => array_map(fn (SeriesView $view): array => [
                'id' => $view->series->id(),
                'label' => $view->series->name(),
                'href' => $this->generateUrl(MeetingController::ROUTE, ['f' => json_encode(['conditions' => [['field' => 'series', 'op' => 'is', 'value' => $view->series->id()]]], \JSON_THROW_ON_ERROR)]),
                'secondary' => $label('meetings.series.visibility.'.$view->series->visibility()->value),
                'cells' => [
                    'name' => $view->series->name(),
                    'visibility' => $label('meetings.series.visibility.'.$view->series->visibility()->value),
                    'members' => implode(', ', array_column($view->members, 'name')),
                    'meetings' => (string) $view->meetings,
                    'last' => $view->lastHeldOn?->format($this->translator->trans('format.date')),
                ],
                'actions' => $view->changeable && !$view->series->isArchived() ? [
                    ['label' => $label('meetings.meeting.new'), 'href' => $this->generateUrl('app_meetings_meeting_new', ['series' => $view->series->id()]), 'icon' => 'plus', 'dialog' => true],
                    ['label' => $label('meetings.series.edit'), 'href' => $this->generateUrl('app_meetings_series_edit', ['id' => $view->series->id()]), 'icon' => 'pencil', 'dialog' => true],
                    ['label' => $label('meetings.series.archive'), 'href' => $this->generateUrl('app_meetings_series_archive', ['id' => $view->series->id()]), 'method' => 'POST', 'icon' => 'archive'],
                ] : [],
            ], $this->reads->views($archived)),
            'token' => $token,
        ]);
    }

    #[Route('/meetings/series/new', name: 'app_meetings_series_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyUnlessStaff();
        if (!$request->isMethod('POST')) {
            // A new series is for its members (spec decision M3), the person creating it among them.
            return $this->form(null, ['name' => '', 'visibility' => SeriesVisibility::Members->value, 'agendaTemplate' => '', 'defaultProjectId' => '', 'members' => array_filter([$this->people->me()], static fn (?string $id): bool => null !== $id), 'defaults' => []], [], Response::HTTP_OK);
        }
        $this->checkToken($request);
        $values = $this->posted($request);
        try {
            $series = $this->writes->create(new CreateSeriesInput($values['name'], $values['visibility'], $values['agendaTemplate'], '' === $values['defaultProjectId'] ? null : $values['defaultProjectId'], $values['members'], $values['defaults']));
        } catch (MeetingsRefused $refused) {
            $this->denyIfForbidden($refused);

            return $this->form(null, $values, $this->errors($refused), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $this->translator->trans('meetings.series.flash.created', ['name' => $series->name()]));

        return $this->redirectToRoute(self::ROUTE, [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/meetings/series/{id}/edit', name: 'app_meetings_series_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $view = $this->view($id);
        $series = $view->series;
        if (!$request->isMethod('POST')) {
            return $this->form($view, [
                'name' => $series->name(),
                'visibility' => $series->visibility()->value,
                'agendaTemplate' => $series->agendaTemplate(),
                'defaultProjectId' => $series->defaultProjectId() ?? '',
                'members' => array_column($view->members, 'id'),
                'defaults' => array_column(array_filter($view->members, static fn (array $member): bool => $member['default_attendee']), 'id'),
            ], [], Response::HTTP_OK);
        }
        $this->checkToken($request);
        $values = $this->posted($request);
        try {
            $this->writes->update(new UpdateSeriesInput($id, $values['name'], $values['visibility'], $values['agendaTemplate'], '' === $values['defaultProjectId'] ? null : $values['defaultProjectId'], $values['members'], $values['defaults']));
        } catch (MeetingsRefused $refused) {
            $this->denyIfForbidden($refused);
            if (RefusalKind::Forbidden === $refused->kind()) {
                throw new AccessDeniedHttpException($refused->getMessage(), $refused);
            }

            return $this->form($view, $values, $this->errors($refused), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $this->translator->trans('meetings.series.flash.saved'));

        return $this->redirectToRoute(self::ROUTE, [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/meetings/series/{id}/archive', name: 'app_meetings_series_archive', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function archive(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        try {
            $series = $this->writes->archive(new ArchiveSeriesInput($id));
        } catch (MeetingsRefused $refused) {
            if (RefusalKind::Forbidden === $refused->kind()) {
                throw new AccessDeniedHttpException($refused->getMessage(), $refused);
            }
            throw new NotFoundHttpException($refused->getMessage(), $refused);
        }
        $this->addFlash('success', $this->translator->trans('meetings.series.flash.archived', ['name' => $series->name()]));

        return $this->redirectToRoute(self::ROUTE, [], Response::HTTP_SEE_OTHER);
    }

    private function view(string $id): SeriesView
    {
        try {
            $view = $this->reads->view($id);
        } catch (MeetingsRefused $refused) {
            $this->denyIfForbidden($refused);
            throw new NotFoundHttpException($refused->getMessage(), $refused);
        }
        if (!$view->changeable) {
            throw new AccessDeniedHttpException('Only the owners and the members of a series change it.');
        }

        return $view;
    }

    /**
     * @param array<string, mixed>  $values
     * @param array<string, string> $errors
     */
    private function form(?SeriesView $view, array $values, array $errors, int $status): Response
    {
        $people = [];
        foreach ($this->people->staff() as $person) {
            $people[$person->membershipId] = $person->name;
        }
        foreach ($view->members ?? [] as $member) {
            $people[$member['id']] ??= $member['name'];
        }
        $projects = ['' => $this->translator->trans('meetings.series.no_project')];
        foreach ($this->work->projects() as $project) {
            $projects[$project->id] = $project->name;
        }

        return $this->render('@Meetings/series/form.html.twig', [
            'series' => $view?->series,
            'action' => $view instanceof SeriesView ? $this->generateUrl('app_meetings_series_edit', ['id' => $view->series->id()]) : $this->generateUrl('app_meetings_series_new'),
            'values' => $values,
            'errors' => $errors,
            'people' => $people,
            'projects' => $projects,
            'visibilities' => array_combine(
                array_column(SeriesVisibility::cases(), 'value'),
                array_map(fn (SeriesVisibility $visibility): string => $this->translator->trans('meetings.series.visibility.'.$visibility->value), SeriesVisibility::cases()),
            ),
        ], new Response(status: $status));
    }

    /**
     * @return array{name: string, visibility: string, agendaTemplate: string, defaultProjectId: string, members: list<string>, defaults: list<string>}
     */
    private function posted(Request $request): array
    {
        return [
            'name' => trim($request->request->getString('name')),
            'visibility' => $request->request->getString('visibility'),
            'agendaTemplate' => str_replace("\r\n", "\n", $request->request->getString('agendaTemplate')),
            'defaultProjectId' => $request->request->getString('defaultProjectId'),
            'members' => array_values(array_filter($request->request->all('members'), \is_string(...))),
            'defaults' => array_values(array_filter($request->request->all('defaults'), \is_string(...))),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function errors(MeetingsRefused $refused): array
    {
        $field = match ($refused->field()) {
            'name', 'visibility', 'agendaTemplate', 'defaultProjectId' => $refused->field(),
            'memberIds' => 'members',
            default => 'name',
        };

        return [$field => $this->translator->trans('meetings.series.error.'.$refused->reason())];
    }

    private function denyUnlessStaff(): void
    {
        if (!$this->visibility->mayUse()) {
            throw new AccessDeniedHttpException('Only the owners and members of the organisation keep its meetings.');
        }
    }

    private function denyIfForbidden(MeetingsRefused $refused): void
    {
        if ('forbidden' === $refused->reason()) {
            throw new AccessDeniedHttpException($refused->getMessage(), $refused);
        }
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(MeetingController::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
