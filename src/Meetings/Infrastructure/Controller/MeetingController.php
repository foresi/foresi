<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Infrastructure\Controller;

use Foresi\Meetings\Application\Api\CreateMeetingInput;
use Foresi\Meetings\Application\Api\RestoreMeetingInput;
use Foresi\Meetings\Application\Api\SetMinutesInput;
use Foresi\Meetings\Application\Api\TrashMeetingInput;
use Foresi\Meetings\Application\Api\UpdateMeetingInput;
use Foresi\Meetings\Application\MeetingHistory;
use Foresi\Meetings\Application\MeetingHistoryEntry;
use Foresi\Meetings\Application\MeetingPeople;
use Foresi\Meetings\Application\MeetingReads;
use Foresi\Meetings\Application\MeetingsClock;
use Foresi\Meetings\Application\MeetingSearch;
use Foresi\Meetings\Application\MeetingsRefused;
use Foresi\Meetings\Application\MeetingsStore;
use Foresi\Meetings\Application\MeetingView;
use Foresi\Meetings\Application\MeetingVisibility;
use Foresi\Meetings\Application\MeetingWrites;
use Foresi\Meetings\Application\PurgeTrashedMeetings;
use Foresi\Meetings\Application\SeriesReads;
use Foresi\Meetings\Application\View\MeetingLists;
use Foresi\Meetings\Contract\Field\MeetingExtension;
use Foresi\Meetings\Domain\Meeting;
use Foresi\Meetings\Domain\MeetingSeries;
use Foresi\Meetings\Domain\SeriesMember;
use Foresi\Meetings\Infrastructure\Column\MeetingColumns;
use Foresi\Meetings\Infrastructure\Screen\MeetingScreen;
use Foresi\Meetings\Infrastructure\Target\ContactMeetingTarget;
use Foresi\Meetings\Infrastructure\Target\ProjectMeetingTarget;
use Foresi\Party\Application\PartyQuery;
use Foresi\Party\Application\PartyRow;
use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\Field\FieldRefused;
use Foresi\Shared\Application\View\FilterRows;
use Foresi\Shared\Application\View\ListView;
use Foresi\Shared\Application\View\ListViews;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\ViewState;
use Foresi\Shared\Application\View\WorkingFilter;
use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Infrastructure\View\FilterSheet;
use Foresi\Ui\Column\RememberedDisplay;
use Foresi\Ui\Contract\WorkingDisplay;
use Foresi\Ui\Contract\WorkingState;
use Foresi\Ui\Field\CustomFieldForm;
use Foresi\Ui\Field\CustomFieldSubmission;
use Foresi\Ui\View\ViewBar;
use Foresi\Work\Application\WorkQueries;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
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
 * The meetings, in the Work area. The list runs on saved views as every list
 * does: All, Upcoming, Past and My meetings, with the series, My meetings and
 * when as quick filters, the filter sheet, the person's own views and the
 * Settings sheet. New meeting and Series are in the page header; a new meeting
 * starts in a dialog with the series' default attendees ticked (Fellow's note
 * series). The meeting's page has Edit in a dialog and Trash in its header menu,
 * its attendees and linked records under the header, the minutes read as
 * Markdown and changed in place in the Markdown editor, as a task's description
 * is (an empty meeting of a series opens with the series' agenda template), a
 * save from an older version refused with the reason, and its history. Without
 * JavaScript every dialog is a page of its own.
 */
final class MeetingController extends AbstractController
{
    public const string ROUTE = 'app_meetings_meetings';
    public const string MEETING_ROUTE = 'app_meetings_meeting';
    public const string TOKEN = 'meetings';

    /** The list's one sort, by the day held, the newest first; "held_on" the oldest first. */
    private const string SORT_DAY = '-held_on';
    private const int MAX_QUERY = 100;

    public function __construct(
        private readonly MeetingLists $meetingLists,
        private readonly MeetingReads $reads,
        private readonly MeetingWrites $writes,
        private readonly MeetingHistory $history,
        private readonly MeetingVisibility $visibility,
        private readonly MeetingPeople $people,
        private readonly MeetingsStore $store,
        private readonly MeetingsClock $clock,
        private readonly SeriesReads $series,
        private readonly MeetingScreen $screen,
        private readonly TranslatorInterface $translator,
        private readonly ListViews $listViews,
        private readonly ActorRole $actorRole,
        private readonly RememberedDisplay $rememberedDisplay,
        private readonly ViewBar $viewBar,
        private readonly FilterSheet $filterSheet,
        private readonly CustomFieldForm $customFields,
        private readonly WorkQueries $work,
        private readonly PartyQuery $parties,
    ) {
    }

    #[Route('/meetings', name: self::ROUTE, methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessStaff();
        $input = $request->query;
        // The builder's rows (spec D11) become the list's working filter, in its address.
        if ($input->has(FilterRows::MARKER)) {
            return $this->redirectToRoute(self::ROUTE, $this->builtQuery($input), Response::HTTP_SEE_OTHER);
        }
        [$views, $view, $shown] = $this->views($input);
        $list = $this->meetingLists->list();
        $filterParam = WorkingFilter::param($shown, $view->filter);
        $text = trim(mb_substr($input->getString('q'), 0, self::MAX_QUERY));
        // A view keeps its own order; the address names the sort only when it differs (spec D10).
        $viewSort = ViewState::sortParam($view->sort, self::SORT_DAY);
        $sort = $input->getString('sort', $viewSort);
        $sort = 'held_on' === ltrim($sort, '-') ? $sort : self::SORT_DAY;
        $query = array_filter(['view' => $view->isAll() ? null : $view->key, 'q' => '' === $text ? null : $text, 'sort' => $viewSort === $sort ? null : $sort, WorkingFilter::PARAM => $filterParam], static fn (?string $value): bool => null !== $value);
        $working = $this->rememberedDisplay->read(MeetingColumns::LIST, [], $list->key, $view, $input->all(), filters: $list);
        $query += $working->query;
        $bar = $this->viewBar->of($list, $views, $view, $shown, $sort, self::SORT_DAY, $working, self::ROUTE, '' === $text ? [] : ['q' => $text]);
        $meetings = $this->reads->search(new MeetingSearch($shown, $text, oldestFirst: !str_starts_with($sort, '-')));
        $this->screen->prime($meetings);

        return $this->render('@Meetings/meetings/index.html.twig', [
            'view' => $view->isAll() ? '' : $view->key,
            'working' => $filterParam ?? '',
            ...$this->filterSheet->of($list, $shown, fn (ViewFilter $next): string => $this->generateUrl(self::ROUTE, [WorkingFilter::PARAM => WorkingFilter::param($next, $view->filter)] + array_diff_key($query, [WorkingFilter::PARAM => true])), $this->references(), pinned: $working->pinned),
            'sortKept' => $viewSort === $sort ? null : $sort,
            ...$bar,
            ...$this->tabs($views, $view->key, $query, $bar['viewBar']['modified']),
            'columns' => $working->display->table($sort, fn (string $next): string => $this->generateUrl(self::ROUTE, ['sort' => $next] + $query)),
            'rows' => array_map(fn (Meeting $meeting): array => $this->row($meeting, $working), $meetings),
            'picker' => $this->picker($working, $query),
            'workingQuery' => $working->query,
            'text' => $text,
            'filtered' => '' !== $text || null !== $filterParam,
            'resetHref' => $this->generateUrl(self::ROUTE, ['view' => $view->isAll() ? null : $view->key]),
        ]);
    }

    /**
     * The series the series filter offers: those the person sees, by name.
     */
    #[Route('/meetings/series-choices', name: 'app_meetings_series_choices', methods: ['GET'])]
    public function seriesChoices(Request $request): JsonResponse
    {
        $this->denyUnlessStaff();
        $text = mb_strtolower(trim(mb_substr($request->query->getString('q'), 0, self::MAX_QUERY)));
        $results = [];
        foreach ($this->series->all(true) as $series) {
            if ('' === $text || str_contains(mb_strtolower($series->name()), $text)) {
                $results[] = ['id' => $series->id(), 'label' => $series->name()];
            }
        }

        return $this->json(['results' => $results]);
    }

    /**
     * The contacts a meeting can be linked to, for the link's picker.
     */
    #[Route('/meetings/contacts', name: 'app_meetings_contacts', methods: ['GET'])]
    public function contacts(Request $request): JsonResponse
    {
        $this->denyUnlessStaff();

        return $this->json(['results' => array_map(static fn (PartyRow $row): array => [
            'id' => $row->id,
            'label' => $row->displayName,
            'hint' => $row->email ?? $row->registrationNumber,
        ], $this->parties->search(mb_substr($request->query->getString('q'), 0, self::MAX_QUERY), null, false, 'name', 1)->rows)]);
    }

    #[Route('/meetings/trash', name: 'app_meetings_trash', methods: ['GET'])]
    public function trash(): Response
    {
        $this->denyUnlessStaff();
        $meetings = $this->reads->search(new MeetingSearch(trash: true));
        $label = fn (string $key): string => $this->translator->trans($key);

        return $this->render('@Meetings/meetings/trash.html.twig', [
            'days' => PurgeTrashedMeetings::DAYS,
            'columns' => [
                ['key' => 'title', 'label' => $label('meetings.meetings.column.title'), 'truncate' => true],
                ['key' => 'heldOn', 'label' => $label('meetings.meetings.column.held_on')],
                ['key' => 'deletedAt', 'label' => $label('meetings.trash.column.deleted_at'), 'phone' => false],
            ],
            'rows' => array_map(fn (Meeting $meeting): array => [
                'id' => $meeting->id(),
                'label' => $meeting->title(),
                'secondary' => $this->screen->when($meeting),
                'cells' => [
                    'title' => $meeting->title(),
                    'heldOn' => $this->screen->when($meeting),
                    'deletedAt' => $meeting->deletedAt()?->setTimezone($this->zone())->format($this->translator->trans('format.datetime')),
                ],
                'actions' => $this->visibility->mayTrash($meeting) ? [[
                    'label' => $label('meetings.meeting.restore'),
                    'href' => $this->generateUrl('app_meetings_meeting_restore', ['id' => $meeting->id()]),
                    'method' => 'POST',
                    'icon' => 'archive-restore',
                    'fields' => ['from' => 'trash'],
                ]] : [],
            ], $meetings),
        ]);
    }

    #[Route('/meetings/new', name: 'app_meetings_meeting_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyUnlessStaff();
        if (!$request->isMethod('POST')) {
            $series = $this->chosenSeries($request->query->getString('series'));

            return $this->form(null, [
                'seriesId' => $series?->id() ?? '',
                'title' => '',
                'heldOn' => $this->clock->today()->format('Y-m-d'),
                'startsAt' => '',
                'attendees' => $series instanceof MeetingSeries ? $this->defaults($series) : array_filter([$this->people->me()], static fn (?string $id): bool => null !== $id),
                'links' => [],
            ], [], Response::HTTP_OK);
        }
        $this->checkToken($request);
        $values = $this->posted($request, true);
        $custom = $this->customFields->read(MeetingExtension::RECORD, $request, true);
        if (!$custom->valid()) {
            return $this->form(null, $values, [], Response::HTTP_UNPROCESSABLE_ENTITY, $custom);
        }
        try {
            $meeting = $this->writes->create(new CreateMeetingInput(
                $values['heldOn'],
                '' === $values['seriesId'] ? null : $values['seriesId'],
                '' === $values['title'] ? null : $values['title'],
                '' === $values['startsAt'] ? null : $values['startsAt'],
                $values['attendees'],
                $values['links'],
            ), $custom->parsed);
        } catch (MeetingsRefused $refused) {
            $this->denyIfForbidden($refused);

            return $this->form(null, $values, $this->errors($refused), Response::HTTP_UNPROCESSABLE_ENTITY, $custom);
        } catch (FieldRefused $refused) {
            return $this->form(null, $values, [], Response::HTTP_UNPROCESSABLE_ENTITY, $custom->refused($refused->key, $this->translator->trans($refused->translationKey)));
        }
        $this->addFlash('success', $this->translator->trans('meetings.meeting.flash.created', ['title' => $meeting->title()]));

        return $this->redirectToRoute(self::MEETING_ROUTE, ['id' => $meeting->id()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/meetings/{id}', name: self::MEETING_ROUTE, requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(string $id, Request $request): Response
    {
        $view = $this->view($id);

        return $this->page($view, 'minutes' === $request->query->getString('edit'), null, null, Response::HTTP_OK);
    }

    #[Route('/meetings/{id}/minutes', name: 'app_meetings_meeting_minutes', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function minutes(string $id, Request $request): Response
    {
        $this->checkToken($request);
        $view = $this->view($id);
        $minutes = str_replace("\r\n", "\n", $request->request->getString('minutes'));
        try {
            $this->writes->setMinutes(new SetMinutesInput($id, $minutes, $request->request->getInt('version')));
        } catch (MeetingsRefused $refused) {
            $this->denyIfForbidden($refused);
            if (RefusalKind::NotFound === $refused->kind()) {
                throw new NotFoundHttpException($refused->getMessage(), $refused);
            }

            // What was typed stays in the editor, under the reason; nothing is merged (spec, Model).
            return $this->page($this->view($id), true, $minutes, $this->translator->trans('meetings.meeting.error.'.$refused->reason()), 'stale_minutes' === $refused->reason() ? Response::HTTP_CONFLICT : Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $this->translator->trans('meetings.meeting.flash.minutes_saved'));

        return $this->redirectToRoute(self::MEETING_ROUTE, ['id' => $view->meeting->id()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/meetings/{id}/edit', name: 'app_meetings_meeting_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $view = $this->view($id);
        $meeting = $view->meeting;
        if (!$request->isMethod('POST')) {
            return $this->form($view, [
                'seriesId' => $meeting->series()?->id() ?? '',
                'title' => $meeting->title(),
                'heldOn' => $meeting->heldOn()->format('Y-m-d'),
                'startsAt' => $meeting->startsAt() ?? '',
                'attendees' => array_column($view->attendees, 'id'),
                'links' => array_map(static fn (array $link): array => ['kind' => $link['kind'], 'id' => $link['id']], $view->links),
            ], [], Response::HTTP_OK);
        }
        $this->checkToken($request);
        $values = $this->posted($request, false);
        $custom = $this->customFields->read(MeetingExtension::RECORD, $request, false);
        if (!$custom->valid()) {
            return $this->form($view, $values, [], Response::HTTP_UNPROCESSABLE_ENTITY, $custom);
        }
        // A record linked that the person does not see stays linked: they cannot have taken it off.
        $seen = array_map(static fn (array $link): string => $link['kind'].':'.$link['id'], $view->links);
        $hidden = [];
        foreach ($this->store->links($meeting) as $link) {
            if (!\in_array($link->kind().':'.$link->targetId(), $seen, true)) {
                $hidden[] = ['kind' => $link->kind(), 'id' => $link->targetId()];
            }
        }
        try {
            $this->writes->update(new UpdateMeetingInput($id, $values['title'], $values['heldOn'], '' === $values['startsAt'] ? null : $values['startsAt'], $values['attendees'], [...$values['links'], ...$hidden]), $custom->parsed);
        } catch (MeetingsRefused $refused) {
            $this->denyIfForbidden($refused);

            return $this->form($view, $values, $this->errors($refused), Response::HTTP_UNPROCESSABLE_ENTITY, $custom);
        } catch (FieldRefused $refused) {
            return $this->form($view, $values, [], Response::HTTP_UNPROCESSABLE_ENTITY, $custom->refused($refused->key, $this->translator->trans($refused->translationKey)));
        }
        $this->addFlash('success', $this->translator->trans('meetings.meeting.flash.saved'));

        return $this->redirectToRoute(self::MEETING_ROUTE, ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/meetings/{id}/trash', name: 'app_meetings_meeting_trash', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function moveToTrash(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $meeting = $this->act(fn (): Meeting => $this->writes->trash(new TrashMeetingInput($id)));
        $this->addFlash('success', $this->translator->trans('meetings.meeting.flash.trashed', ['title' => $meeting->title(), 'days' => PurgeTrashedMeetings::DAYS]));

        return $this->redirectToRoute(self::ROUTE, [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/meetings/{id}/restore', name: 'app_meetings_meeting_restore', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function restore(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $meeting = $this->act(fn (): Meeting => $this->writes->restore(new RestoreMeetingInput($id)));
        $this->addFlash('success', $this->translator->trans('meetings.meeting.flash.restored', ['title' => $meeting->title()]));

        return 'trash' === $request->request->getString('from')
            ? $this->redirectToRoute('app_meetings_trash', [], Response::HTTP_SEE_OTHER)
            : $this->redirectToRoute(self::MEETING_ROUTE, ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    /**
     * The meeting's page: the minutes read, or open in the editor with what was typed and why it was refused.
     */
    private function page(MeetingView $view, bool $editing, ?string $typed, ?string $minutesError, int $status): Response
    {
        $meeting = $view->meeting;
        $series = $meeting->series();
        // An empty meeting of a series opens with the series' agenda template (Fellow's and Notion's meeting templates).
        $draft = $typed ?? ('' === $meeting->minutes() && $series instanceof MeetingSeries ? $series->agendaTemplate() : $meeting->minutes());

        return $this->render('@Meetings/meetings/show.html.twig', [
            'meeting' => $meeting,
            'view' => $view,
            'when' => $this->screen->when($meeting),
            'editing' => ($editing || null !== $typed) && !$meeting->isTrashed(),
            'draft' => $draft,
            'minutesError' => $minutesError,
            'history' => array_map($this->historyItem(...), $this->history->of($meeting->id())),
        ], new Response(status: $status));
    }

    /**
     * @return array{time: string, datetime: string, icon: string, text: string}
     */
    private function historyItem(MeetingHistoryEntry $entry): array
    {
        $who = $entry->userName ?? $this->translator->trans('meetings.history.someone');
        $parts = [];
        if ([] !== $entry->fields) {
            $parts[] = $this->translator->trans('meetings.history.fields', ['fields' => implode(', ', array_map(fn (string $key): string => mb_strtolower($this->translator->trans($key)), $entry->fields))]);
        }
        if ([] !== $entry->added) {
            $parts[] = $this->translator->trans('meetings.history.attendees_added', ['names' => implode(', ', $entry->added)]);
        }
        if ([] !== $entry->removed) {
            $parts[] = $this->translator->trans('meetings.history.attendees_removed', ['names' => implode(', ', $entry->removed)]);
        }
        if ($entry->linked > 0) {
            $parts[] = $this->translator->trans('meetings.history.linked', ['count' => $entry->linked]);
        }
        if ($entry->unlinked > 0) {
            $parts[] = $this->translator->trans('meetings.history.unlinked', ['count' => $entry->unlinked]);
        }
        $text = match ($entry->kind) {
            MeetingHistoryEntry::CREATED => $this->translator->trans('meetings.history.created', ['who' => $who]),
            MeetingHistoryEntry::TRASHED => $this->translator->trans('meetings.history.trashed', ['who' => $who]),
            MeetingHistoryEntry::RESTORED => $this->translator->trans('meetings.history.restored', ['who' => $who]),
            default => $this->translator->trans('meetings.history.changed', ['who' => $who, 'what' => implode('; ', $parts)]),
        };
        $at = $entry->at->setTimezone($this->zone());

        return [
            'time' => $at->format($this->translator->trans('format.datetime')),
            'datetime' => $at->format(\DATE_ATOM),
            'icon' => match ($entry->kind) {
                MeetingHistoryEntry::CREATED => 'plus',
                MeetingHistoryEntry::TRASHED => 'trash-2',
                MeetingHistoryEntry::RESTORED => 'archive-restore',
                default => 'pencil',
            },
            'text' => $text,
        ];
    }

    /**
     * @param array<string, mixed>       $values
     * @param array<string, string>      $errors
     * @param CustomFieldSubmission|null $custom the custom fields posted; null shows the meeting's own
     */
    private function form(?MeetingView $view, array $values, array $errors, int $status, ?CustomFieldSubmission $custom = null): Response
    {
        $meeting = $view?->meeting;
        $series = [];
        $defaults = [];
        foreach ($this->series->all() as $one) {
            $series[$one->id()] = $one->name();
            $defaults[$one->id()] = ['attendees' => $this->defaults($one), 'name' => $one->name()];
        }
        $people = [];
        foreach ($this->people->staff() as $person) {
            $people[$person->membershipId] = $person->name;
        }
        // An attendee who left the firm stays listed while they are on the meeting.
        foreach ($view->attendees ?? [] as $attendee) {
            $people[$attendee['id']] ??= $attendee['name'];
        }
        $projects = ['' => $this->translator->trans('meetings.meeting.link.no_project')];
        foreach ($this->work->projects() as $project) {
            $projects[$project->id] = $project->name;
        }
        $linked = [];
        foreach ($this->linksOf($values['links'] ?? []) as $link) {
            $seen = null;
            foreach ($view->links ?? [] as $known) {
                if ($known['kind'] === $link['kind'] && $known['id'] === $link['id']) {
                    $seen = $known;
                }
            }
            $label = $seen['label'] ?? (ProjectMeetingTarget::KIND === $link['kind'] ? ($projects[$link['id']] ?? null) : null);
            if (null !== $label) {
                $linked[$link['kind'].':'.$link['id']] = $this->translator->trans('meetings.meeting.link.kind.'.$link['kind']).': '.$label;
            }
        }

        return $this->render('@Meetings/meetings/form.html.twig', [
            'meeting' => $meeting,
            'action' => $meeting instanceof Meeting ? $this->generateUrl('app_meetings_meeting_edit', ['id' => $meeting->id()]) : $this->generateUrl('app_meetings_meeting_new'),
            'values' => $values,
            'errors' => $errors,
            'seriesOptions' => ['' => $this->translator->trans('meetings.meeting.one_off')] + $series,
            'seriesDefaults' => $defaults,
            'people' => $people,
            'projects' => $projects,
            'linked' => $linked,
            'customValues' => $custom?->values,
            'customErrors' => $custom->errors ?? [],
        ], new Response(status: $status));
    }

    /**
     * The form as posted: the links kept, a project and a contact added.
     *
     * @return array{seriesId: string, title: string, heldOn: string, startsAt: string, attendees: list<string>, links: list<array{kind: string, id: string}>}
     */
    private function posted(Request $request, bool $creating): array
    {
        $links = [];
        foreach ($request->request->all('links') as $link) {
            if (\is_string($link) && 1 === preg_match('/^([a-z_]+):(.+)$/D', $link, $match)) {
                $links[$link] = ['kind' => $match[1], 'id' => $match[2]];
            }
        }
        foreach ([ProjectMeetingTarget::KIND => $request->request->getString('project'), ContactMeetingTarget::KIND => $request->request->getString('contactId')] as $kind => $id) {
            if ('' !== $id) {
                $links[$kind.':'.$id] = ['kind' => $kind, 'id' => $id];
            }
        }

        return [
            'seriesId' => $creating ? trim($request->request->getString('seriesId')) : '',
            'title' => trim($request->request->getString('title')),
            'heldOn' => trim($request->request->getString('heldOn')),
            'startsAt' => trim($request->request->getString('startsAt')),
            'attendees' => array_values(array_filter($request->request->all('attendees'), \is_string(...))),
            'links' => array_values($links),
        ];
    }

    /**
     * @return array<string, string> the message of the refused field, by form field
     */
    private function errors(MeetingsRefused $refused): array
    {
        $field = match ($refused->field()) {
            'seriesId', 'title', 'heldOn', 'startsAt' => $refused->field(),
            'attendeeIds' => 'attendees',
            'links' => 'links',
            default => 'title',
        };

        return [$field => $this->translator->trans('meetings.meeting.error.'.$refused->reason())];
    }

    /**
     * @return list<string> the membership ids of the series' default attendees still in the firm
     */
    private function defaults(MeetingSeries $series): array
    {
        return array_values(array_filter(array_map(static fn (SeriesMember $member): ?string => $member->isDefaultAttendee() ? $member->membershipId() : null, $this->store->members($series)), fn (?string $id): bool => null !== $id && $this->people->isStaff($id)));
    }

    private function chosenSeries(string $id): ?MeetingSeries
    {
        if ('' === $id) {
            return null;
        }
        try {
            $series = $this->series->one($id);
        } catch (MeetingsRefused) {
            return null;
        }

        return $series->isArchived() ? null : $series;
    }

    /**
     * @return array<string, array{url: string, name: \Closure(string): ?string}>
     */
    private function references(): array
    {
        return [
            MeetingLists::SOURCE_SERIES => ['url' => $this->generateUrl('app_meetings_series_choices'), 'name' => function (string $id): ?string {
                try {
                    return $this->series->one($id)->name();
                } catch (MeetingsRefused) {
                    return null;
                }
            }],
        ];
    }

    /**
     * @param \Closure(): Meeting $write
     */
    private function act(\Closure $write): Meeting
    {
        try {
            return $write();
        } catch (MeetingsRefused $refused) {
            $this->denyIfForbidden($refused);
            throw new NotFoundHttpException($refused->getMessage(), $refused);
        }
    }

    private function view(string $id): MeetingView
    {
        try {
            return $this->reads->view($id);
        } catch (MeetingsRefused $refused) {
            $this->denyIfForbidden($refused);
            throw new NotFoundHttpException($refused->getMessage(), $refused);
        }
    }

    /**
     * @param InputBag<string> $input
     *
     * @return array{list<ListView>, ListView, ViewFilter}
     */
    private function views(InputBag $input): array
    {
        $list = $this->meetingLists->list();
        $views = $this->listViews->of($list, $this->actorRole->userId());
        $view = ListViews::chosen($views, $input->getString('view'));

        return [$views, $view, WorkingFilter::decode($input->getString(WorkingFilter::PARAM), $list) ?? $view->filter];
    }

    /**
     * @param InputBag<string> $input
     *
     * @return array<string, mixed>
     */
    private function builtQuery(InputBag $input): array
    {
        [, $view] = $this->views($input);
        $query = array_diff_key($input->all(), [FilterRows::PARAM => true, FilterRows::MARKER => true, WorkingFilter::PARAM => true, 'view' => true]);

        return array_filter($query + ['view' => $view->isAll() ? null : $view->key, WorkingFilter::PARAM => WorkingFilter::param(FilterRows::parse($input->all(FilterRows::PARAM), $this->meetingLists->list()), $view->filter)], static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    /**
     * @param list<ListView>        $views
     * @param array<string, string> $query
     *
     * @return array{tabs: list<array<string, mixed>>, moreViews: list<array<string, mixed>>}
     */
    private function tabs(array $views, string $active, array $query, bool $modified): array
    {
        [$primary, $overflow] = ListViews::placed($views);
        $link = fn (ListView $view): array => [
            'label' => $view->translated ? $this->translator->trans($view->name) : $view->name,
            'href' => $this->generateUrl(self::ROUTE, ['view' => $view->isAll() ? null : $view->key] + array_diff_key($query, ['view' => true, 'sort' => true, WorkingFilter::PARAM => true] + array_flip(WorkingDisplay::KEYS))),
            'active' => $view->key === $active,
            'modified' => $modified && $view->key === $active,
            'private' => $view->private,
        ];

        return ['tabs' => array_map($link, $primary), 'moreViews' => array_map($link, $overflow)];
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function picker(WorkingState $working, array $query): array
    {
        $kept = array_diff_key($query, array_flip(WorkingDisplay::KEYS));

        return [
            'keep' => $kept,
            'columns' => $working->picker(),
            // Empty widths name the view's own display, which forgets the one remembered (D-display-3).
            'resetHref' => $this->generateUrl(self::ROUTE, $kept + [WorkingDisplay::WIDTHS => '']),
            'modified' => $working->modified,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Meeting $meeting, WorkingState $working): array
    {
        return [
            'id' => $meeting->id(),
            'href' => $this->generateUrl(self::MEETING_ROUTE, ['id' => $meeting->id()]),
            'label' => $meeting->title(),
            'secondary' => implode(' · ', array_filter([$this->screen->when($meeting), $this->screen->series($meeting)], static fn (string $part): bool => '' !== $part)),
            'cells' => $working->display->cells($meeting),
        ];
    }

    /**
     * The links of the form's values, each a kind and an id.
     *
     * @return list<array{kind: string, id: string}>
     */
    private function linksOf(mixed $links): array
    {
        $found = [];
        foreach (\is_array($links) ? $links : [] as $link) {
            if (\is_array($link) && \is_string($link['kind'] ?? null) && \is_string($link['id'] ?? null)) {
                $found[] = ['kind' => $link['kind'], 'id' => $link['id']];
            }
        }

        return $found;
    }

    private function zone(): \DateTimeZone
    {
        return $this->clock->zone();
    }

    private function denyUnlessStaff(): void
    {
        if (!$this->visibility->mayUse()) {
            throw new AccessDeniedHttpException('Only the owners and members of the organisation keep its meetings.');
        }
    }

    private function denyIfForbidden(MeetingsRefused $refused): void
    {
        if (RefusalKind::Forbidden === $refused->kind() && 'forbidden' === $refused->reason()) {
            throw new AccessDeniedHttpException($refused->getMessage(), $refused);
        }
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
