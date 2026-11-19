<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Controller;

use Foresi\Identity\Application\OwnerCheck;
use Foresi\Identity\Application\People;
use Foresi\Identity\Domain\Role;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Application\PartyQuery;
use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\DateRange;
use Foresi\Shared\Application\View\FilterRows;
use Foresi\Shared\Application\View\InvalidFilter;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListView;
use Foresi\Shared\Application\View\ListViews;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\WorkingFilter;
use Foresi\Shared\Infrastructure\View\FilterSheet;
use Foresi\Ui\Column\RememberedDisplay;
use Foresi\Ui\Contract\ListDisplay;
use Foresi\Ui\Contract\Opens;
use Foresi\Ui\Contract\WorkingDisplay;
use Foresi\Ui\Contract\WorkingState;
use Foresi\Ui\View\ViewBar;
use Foresi\Work\Application\TimeEntryRow;
use Foresi\Work\Application\TimeGrouping;
use Foresi\Work\Application\TimeQueries;
use Foresi\Work\Application\TimeReportFilter;
use Foresi\Work\Application\TimeTotal;
use Foresi\Work\Application\View\TimeReportLists;
use Foresi\Work\Application\WorkQueries;
use Foresi\Work\Infrastructure\Column\TimeReportColumns;
use Foresi\Work\Infrastructure\Column\TimeReportEntryRow;
use Foresi\Work\Infrastructure\Export\TimeReportCsv;
use Foresi\Work\Infrastructure\Twig\TimeFunctions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The time report "Výkaz" on saved views, as every list has them: All, This month (the
 * view it opens on, as the report showed this month unless asked otherwise) and Last
 * month, the person's own views, "+ Filter" (the day, the project, the client, the
 * person, billable, invoiced) and the Columns sheet of its entries. What the totals add
 * up by (project, person or task) is the view's display, kept with it as its columns
 * are (D-display-2). Its sums, its totals and its entries, newest first (Freelo reports,
 * Productive "invoiced = no"); the header downloads the entries as CSV with the same
 * filter. It holds only the time the person sees: with its task, or with the whole
 * project for time without one. Guests have no time: the page is closed to them.
 *
 * The addresses of the report before saved views (?period=, ?from=, ?until=, ?project=,
 * ?client=, ?person=, ?billable=, ?invoiced=) open the same time as a working filter.
 */
final class TimeReportController extends AbstractController
{
    public const string ROUTE = 'app_time_report';
    public const string TOKEN = 'work_time_report';
    /** What the totals add up by, the default first. */
    private const array GROUPS = ['project', 'person', 'task'];
    /** The fields of the report's address before saved views. */
    private const array LEGACY = ['period', 'from', 'until', 'project', 'client', 'person', 'billable', 'invoiced'];
    private const int MAX_QUERY = 100;

    public function __construct(
        private readonly TimeQueries $time,
        private readonly WorkQueries $work,
        private readonly People $people,
        private readonly InstanceClock $clock,
        private readonly TranslatorInterface $translator,
        private readonly TimeFunctions $format,
        private readonly TimeReportCsv $csv,
        private readonly OwnerCheck $owner,
        private readonly PartyQuery $parties,
        private readonly ListViews $listViews,
        private readonly ActorRole $actorRole,
        private readonly RememberedDisplay $rememberedDisplay,
        private readonly ViewBar $viewBar,
        private readonly FilterSheet $filterSheet,
    ) {
    }

    #[Route('/time/report', name: self::ROUTE, methods: ['GET'])]
    public function index(Request $request): Response
    {
        $input = $request->query;
        // The builder's rows (spec D11) become the report's working filter, in its address.
        if ($input->has(FilterRows::MARKER)) {
            return $this->redirectToRoute(self::ROUTE, $this->builtQuery($input), Response::HTTP_SEE_OTHER);
        }
        $legacy = $this->legacyQuery($input);
        if (null !== $legacy) {
            return $this->redirectToRoute(self::ROUTE, $legacy, Response::HTTP_SEE_OTHER);
        }
        [$views, $view, $shown] = $this->reportViews($input);
        $list = TimeReportLists::list();
        $filterParam = WorkingFilter::param($shown, $view->filter);
        // The columns, the density and the grouping, kept in the address next to the view and for the person (spec D10, D-display-3).
        $working = $this->rememberedDisplay->read(TimeReportColumns::LIST, [], $list->key, $view, $input->all(), ListDisplay::DENSE, self::GROUPS);
        $grouping = TimeGrouping::tryFrom($working->display->groupBy ?? '') ?? TimeGrouping::Project;
        $query = array_filter(['view' => $view->key, WorkingFilter::PARAM => $filterParam], static fn (?string $value): bool => null !== $value) + $working->query;
        $bar = $this->viewBar->of($list, $views, $view, $shown, '', '', $working, self::ROUTE, []);
        $report = $this->time->report(TimeReportFilter::ofView($shown), $grouping, max(1, $input->getInt('page', 1)));
        $options = $this->options();

        return $this->render('@Work/time/report.html.twig', [
            'query' => $query,
            'view' => $view->key,
            'working' => $filterParam ?? '',
            ...$this->filterSheet->of($list, $shown, fn (ViewFilter $next): string => $this->generateUrl(self::ROUTE, [WorkingFilter::PARAM => WorkingFilter::param($next, $view->filter)] + array_diff_key($query, [WorkingFilter::PARAM => true])), $this->references($options)),
            ...$bar,
            ...$this->viewTabs($views, $view->key, $query, $bar['viewBar']['modified']),
            'range' => $this->range($shown, $view),
            'kpis' => [
                ['label' => $this->translator->trans('work.time.report.total'), 'value' => $this->format->duration($report->totalMinutes)],
                ['label' => $this->translator->trans('work.time.report.billable_total'), 'value' => $this->format->duration($report->billableMinutes)],
                ['label' => $this->translator->trans('work.time.report.unbillable_total'), 'value' => $this->format->duration($report->totalMinutes - $report->billableMinutes)],
                ['label' => $this->translator->trans('work.time.report.entries'), 'value' => (string) $report->entryCount],
            ],
            'empty' => 0 === $report->entryCount,
            'totalColumns' => $this->totalColumns($grouping),
            'totalRows' => array_map($this->totalRow(...), $report->totals),
            'totalSums' => ['minutes' => $this->format->duration($report->totalMinutes), 'billable' => $this->format->duration($report->billableMinutes), 'entries' => (string) $report->entryCount],
            'grouping' => $grouping->value,
            'groupings' => array_map(fn (TimeGrouping $group): array => [
                'label' => $this->translator->trans('work.time.report.by.'.$group->value),
                'href' => $this->generateUrl(self::ROUTE, [WorkingDisplay::GROUP => $group->value] + array_diff_key($query, [WorkingDisplay::GROUP => true])),
                'checked' => $group === $grouping,
            ], TimeGrouping::cases()),
            'entryColumns' => $working->display->table(),
            'entryRows' => array_map(fn (TimeEntryRow $entry): array => $this->entryRow($entry, $working), $report->entries),
            'density' => $working->tableDensity(),
            'picker' => $this->picker($working, $query),
            'workingQuery' => $working->query,
            'entryCount' => $report->entryCount,
            'page' => $report->page,
            'pages' => $report->pages,
            'baseUrl' => $this->generateUrl(self::ROUTE, $query),
            // Only an owner sees rates, so only an owner sees the actions that bill time; the client of the filter comes along.
            'billing' => $this->owner->isOwner() ? ['clientId' => $this->client($shown)] : null,
        ]);
    }

    /**
     * Downloads every entry of the report as its view and filter stand, oldest first.
     */
    #[Route('/time/report/export', name: 'app_time_report_export', methods: ['POST'])]
    public function export(Request $request): StreamedResponse
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        [, , $shown] = $this->reportViews($request->request);
        $period = $this->period($shown);
        $name = $this->translator->trans('work.time.report.file').(null === $period ? '' : \sprintf('_%s_%s', $period[0]->format('Y-m-d'), $period[1]->format('Y-m-d'))).'.csv';
        // The request has ended by the time the response streams, and its locale with it.
        $locale = $request->getLocale();
        $response = new StreamedResponse(function () use ($shown, $locale): void {
            $this->csv->write($this->time->reportEntries(TimeReportFilter::ofView($shown)), 'php://output', $locale);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, 'time.csv'));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * The projects a project condition picks from: those the person sees, archived ones
     * after the active ones, with their client.
     */
    #[Route('/time/report/projects', name: 'app_time_report_projects', methods: ['GET'])]
    public function projects(Request $request): JsonResponse
    {
        return $this->picked($this->options()['projects'], $request);
    }

    /**
     * The people a person condition picks from: the staff of the organisation, by name.
     */
    #[Route('/time/report/people', name: 'app_time_report_people', methods: ['GET'])]
    public function people(Request $request): JsonResponse
    {
        return $this->picked($this->options()['people'], $request);
    }

    /**
     * @param array<string, string> $offered
     */
    private function picked(array $offered, Request $request): JsonResponse
    {
        $q = mb_strtolower(trim(mb_substr($request->query->getString('q'), 0, self::MAX_QUERY)));
        $results = [];
        foreach ($offered as $id => $label) {
            if ('' === $q || str_contains(mb_strtolower($label), $q)) {
                $results[] = ['id' => $id, 'label' => $label];
            }
        }

        return $this->json(['results' => $results]);
    }

    /**
     * The report's views as its address names them (spec D10): the person's views, the
     * one ?view= names, This month when it names none, and the filter the report shows,
     * the view's own unless f= changed it. Reset of All names no view but the view's own
     * display (an empty density=), and opens All again.
     *
     * @param InputBag<string> $input
     *
     * @return array{list<ListView>, ListView, ViewFilter}
     */
    private function reportViews(InputBag $input): array
    {
        $list = TimeReportLists::list();
        $views = $this->listViews->of($list, $this->actorRole->userId());
        $key = $input->getString('view', $input->has(WorkingDisplay::DENSITY) ? ListDefinition::ALL : TimeReportLists::THIS_MONTH);
        $view = ListViews::chosen($views, $key);

        return [$views, $view, WorkingFilter::decode($input->getString(WorkingFilter::PARAM), $list) ?? $view->filter];
    }

    /**
     * The report's address after the builder's Apply: what the sheet sent, its rows read
     * into the working filter, which is left out when it is the view's own.
     *
     * @param InputBag<string> $input
     *
     * @return array<string, mixed>
     */
    private function builtQuery(InputBag $input): array
    {
        [, $view] = $this->reportViews($input);
        $query = array_diff_key($input->all(), [FilterRows::PARAM => true, FilterRows::MARKER => true, WorkingFilter::PARAM => true, 'view' => true, 'page' => true]);

        return array_filter($query + ['view' => $view->key, WorkingFilter::PARAM => WorkingFilter::param(FilterRows::parse($input->all(FilterRows::PARAM), TimeReportLists::list()), $view->filter)], static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    /**
     * The address of the report before saved views as a view and its working filter
     * (spec D12): the period it named, This month unless another, with the dates of a
     * custom one as a range, and each filter it had as a condition. Null for an address
     * that names none of them.
     *
     * @param InputBag<string> $input
     *
     * @return array<string, string>|null
     */
    private function legacyQuery(InputBag $input): ?array
    {
        if ($input->has('view') || [] === array_intersect(array_keys($input->all()), self::LEGACY)) {
            return null;
        }
        $period = TimeReportLists::LAST_MONTH === $input->getString('period') ? TimeReportLists::LAST_MONTH : TimeReportLists::THIS_MONTH;
        $base = TimeReportLists::period($period);
        $from = $this->day($input->getString('from'));
        $until = $this->day($input->getString('until'));
        $conditions = [null !== $from && null !== $until && 'custom' === $input->getString('period')
            ? new Condition(TimeReportLists::WORKED_ON, 'between', ['from' => min($from, $until), 'to' => max($from, $until)])
            : $base];
        foreach ([TimeReportLists::PROJECT, TimeReportLists::CLIENT, TimeReportLists::PERSON] as $field) {
            if ('' !== $input->getString($field)) {
                $conditions[] = new Condition($field, 'is', $input->getString($field));
            }
        }
        foreach ([TimeReportLists::BILLABLE, TimeReportLists::INVOICED] as $field) {
            $choice = ['yes' => true, 'no' => false][$input->getString($field)] ?? null;
            if (null !== $choice) {
                $conditions[] = new Condition($field, 'is', $choice);
            }
        }
        $kept = array_diff_key($input->all(), array_flip([...self::LEGACY, 'shown', 'page']));

        return array_filter(['view' => $period, WorkingFilter::PARAM => WorkingFilter::param(new ViewFilter($conditions), ViewFilter::all($base))], static fn (?string $value): bool => null !== $value) + array_filter($kept, \is_string(...));
    }

    private function day(string $value): ?string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false !== $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    /**
     * The first and the last day of the period the filter's one day condition names, as
     * "is" or "between"; null for any other filter.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}|null
     */
    private function period(ViewFilter $filter): ?array
    {
        $days = $filter->on(TimeReportLists::WORKED_ON);
        $condition = 1 === \count($days) ? $days[0] : null;
        if (!$condition instanceof Condition || !\in_array($condition->op, ['is', 'between'], true)) {
            return null;
        }
        $today = $this->clock->today();
        try {
            [$start, $end] = 'between' === $condition->op && \is_array($condition->value)
                ? [DateRange::of($condition->value['from'] ?? null, $today)->start, DateRange::of($condition->value['to'] ?? null, $today)->end]
                : [DateRange::of($condition->value, $today)->start, DateRange::of($condition->value, $today)->end];
        } catch (InvalidFilter) {
            return null;
        }

        return [$start, $end->modify('-1 day')];
    }

    /**
     * The heading of the report: the days of its period, all the time when it names no
     * day, or the view's name for any other condition on the day.
     */
    private function range(ViewFilter $filter, ListView $view): string
    {
        $period = $this->period($filter);
        if (null !== $period) {
            $date = $this->translator->trans('format.date');

            return $this->translator->trans('work.time.week_range', ['from' => $period[0]->format($date), 'until' => $period[1]->format($date)]);
        }
        if ([] === $filter->on(TimeReportLists::WORKED_ON)) {
            return $this->translator->trans('work.time.report.range_all');
        }

        return $view->translated ? $this->translator->trans($view->name) : $view->name;
    }

    /**
     * The client the filter names alone, which the actions that bill time start from.
     */
    private function client(ViewFilter $filter): ?string
    {
        $clients = $filter->on(TimeReportLists::CLIENT);
        $condition = 1 === \count($clients) ? $clients[0] : null;

        return $condition instanceof Condition && 'is' === $condition->op && \is_string($condition->value) ? $condition->value : null;
    }

    /**
     * What each reference of the builder picks from and how it names an id: the
     * projects and the people the report offers, and any contact as a client.
     *
     * @param array{projects: array<string, string>, people: array<string, string>} $options
     *
     * @return array<string, array{url: string, name: \Closure(string): ?string}>
     */
    private function references(array $options): array
    {
        return [
            TimeReportLists::PROJECTS => ['url' => $this->generateUrl('app_time_report_projects'), 'name' => static fn (string $id): ?string => $options['projects'][$id] ?? null],
            TimeReportLists::PEOPLE => ['url' => $this->generateUrl('app_time_report_people'), 'name' => static fn (string $id): ?string => $options['people'][$id] ?? null],
            TimeReportLists::CONTACTS => ['url' => $this->generateUrl('app_project_clients'), 'name' => fn (string $id): ?string => $this->parties->find($id)?->displayName()],
        ];
    }

    /**
     * The tabs of the report and its "More views" menu (spec D7): All and the primary
     * views as tabs, the other views in the menu. The report counts none (D9). Every link
     * names its view, as the report's address without one opens This month.
     *
     * @param list<ListView>        $views
     * @param array<string, string> $query
     *
     * @return array{tabs: list<array<string, mixed>>, moreViews: list<array<string, mixed>>}
     */
    private function viewTabs(array $views, string $active, array $query, bool $modified): array
    {
        [$primary, $overflow] = ListViews::placed($views);
        $link = fn (ListView $view): array => [
            'label' => $view->translated ? $this->translator->trans($view->name) : $view->name,
            // A view opens with its own filter and display: the changed ones belong to the view they were changed on (spec D10).
            'href' => $this->generateUrl(self::ROUTE, ['view' => $view->key] + array_diff_key($query, ['view' => true, WorkingFilter::PARAM => true] + array_flip(WorkingDisplay::KEYS))),
            'active' => $view->key === $active,
            'modified' => $modified && $view->key === $active,
            'private' => $view->private,
        ];

        return ['tabs' => array_map($link, $primary), 'moreViews' => array_map($link, $overflow)];
    }

    /**
     * The Columns sheet: what it keeps of the report, and the report without the changed columns.
     *
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function picker(WorkingState $working, array $query): array
    {
        $kept = array_diff_key($query, array_flip([WorkingDisplay::COLUMNS, WorkingDisplay::DENSITY]));

        return [
            'keep' => $kept,
            'columns' => $working->picker(),
            'density' => $working->density,
            // An empty density names the view's own display, which forgets the one remembered (D-display-3).
            'resetHref' => $this->generateUrl(self::ROUTE, $kept + [WorkingDisplay::DENSITY => '']),
            'modified' => $working->modified,
        ];
    }

    /**
     * The projects the person sees, archived ones after the active ones, and the people
     * of the organisation, by name.
     *
     * @return array{projects: array<string, string>, people: array<string, string>}
     */
    private function options(): array
    {
        $projects = $people = [];
        foreach ([false, true] as $archived) {
            foreach ($this->work->projects($archived) as $project) {
                $projects[$project->id] = $archived ? $this->translator->trans('work.time.report.archived', ['name' => $project->name]) : $project->name;
            }
        }
        foreach ($this->people->all() as $person) {
            if (Role::Guest !== $person->role) {
                $people[$person->membershipId] = $person->name;
            }
        }
        asort($people, \SORT_NATURAL | \SORT_FLAG_CASE);

        return ['projects' => $projects, 'people' => $people];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function totalColumns(TimeGrouping $grouping): array
    {
        $columns = [['key' => 'name', 'label' => $this->translator->trans('work.time.report.by.'.$grouping->value), 'truncate' => true]];
        if (TimeGrouping::Task === $grouping) {
            $columns[] = ['key' => 'project', 'label' => $this->translator->trans('work.task.field.project'), 'truncate' => true, 'phone' => false];
        }

        return [
            ...$columns,
            ['key' => 'entries', 'label' => $this->translator->trans('work.time.report.entries'), 'align' => 'right', 'phone' => false],
            ['key' => 'billable', 'label' => $this->translator->trans('work.time.report.billable_total'), 'align' => 'right', 'phone' => false],
            ['key' => 'minutes', 'label' => $this->translator->trans('work.time.report.column.time'), 'align' => 'right'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function totalRow(TimeTotal $total): array
    {
        $name = $total->label ?? $this->translator->trans('work.time.no_task');

        return [
            'id' => $total->key,
            'label' => $name,
            'secondary' => $total->detail,
            'cells' => [
                'name' => $name,
                'project' => $total->detail,
                'entries' => (string) $total->entries,
                'billable' => $this->format->duration($total->billableMinutes),
                'minutes' => $this->format->duration($total->minutes),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entryRow(TimeEntryRow $entry, WorkingState $working): array
    {
        $title = $entry->taskTitle ?? $entry->projectName;
        $day = $entry->workedOn->format($this->translator->trans('format.date'));

        return [
            'id' => $entry->id,
            'href' => null === $entry->taskId ? $this->generateUrl(ProjectController::PROJECT_ROUTE, ['id' => $entry->projectId]) : $this->generateUrl(TaskController::ROUTE, ['id' => $entry->taskId]),
            // A task opens in the sheet; a project is a place of its own, its page.
            'opens' => null === $entry->taskId ? Opens::PAGE : Opens::SHEET,
            'label' => $title,
            // On a phone the line under the work says when, who and where.
            'secondary' => implode(' · ', null === $entry->taskId ? [$day, $entry->personName] : [$day, $entry->personName, $entry->projectName]),
            'cells' => $working->display->cells(new TimeReportEntryRow(
                $title,
                $entry->projectName,
                $entry->personName,
                $day,
                $entry->note,
                $entry->billable ? ['text' => $this->translator->trans('work.time.billable'), 'tone' => 'green'] : null,
                $this->format->duration((int) $entry->minutes),
            )),
        ];
    }
}
