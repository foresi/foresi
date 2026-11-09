<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Controller;

use App\Identity\Application\People;
use App\Identity\Domain\Role;
use App\Tenancy\Application\TenantClock;
use App\Work\Application\TimeEntryRow;
use App\Work\Application\TimeGrouping;
use App\Work\Application\TimeQueries;
use App\Work\Application\TimeReportFilter;
use App\Work\Application\TimeTotal;
use App\Work\Application\WorkQueries;
use App\Work\Infrastructure\Export\TimeReportCsv;
use App\Work\Infrastructure\Twig\TimeFunctions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The time report "Výkaz": the time of a period, this month unless another is
 * chosen, narrowed to a project, a client, a person, billable or not and
 * invoiced or not; its totals by project, person or task, and its entries,
 * newest first (Freelo reports, Productive "invoiced = no"). The header
 * downloads it as CSV with the same filter. It holds only the time the person
 * sees: with its task, or with the whole project for time without one. Guests
 * have no time: the page is closed to them.
 *
 * The dates of the period are shown with it; changing them, the period kept,
 * makes the period a custom one, so the form needs no JavaScript.
 */
final class TimeReportController extends AbstractController
{
    public const string ROUTE = 'app_time_report';
    public const string TOKEN = 'work_time_report';
    private const string THIS_MONTH = 'this_month';
    private const string LAST_MONTH = 'last_month';
    private const string CUSTOM = 'custom';
    private const array PERIODS = [self::THIS_MONTH, self::LAST_MONTH, self::CUSTOM];
    private const array CHOICES = ['', 'yes', 'no'];

    public function __construct(
        private readonly TimeQueries $time,
        private readonly WorkQueries $work,
        private readonly People $people,
        private readonly TenantClock $clock,
        private readonly TranslatorInterface $translator,
        private readonly TimeFunctions $format,
        private readonly TimeReportCsv $csv,
    ) {
    }

    #[Route('/time/report', name: self::ROUTE, methods: ['GET'])]
    public function index(Request $request): Response
    {
        $options = $this->options();
        [$filter, $query, $grouping] = $this->filterOf($request->query, $options);
        $report = $this->time->report($filter, $grouping, max(1, $request->query->getInt('page', 1)));
        $date = $this->translator->trans('format.date');
        $baseUrl = $this->generateUrl(self::ROUTE, $query);

        return $this->render('work/time/report.html.twig', [
            'query' => $query,
            'from' => $filter->from->format('Y-m-d'),
            'until' => $filter->until->format('Y-m-d'),
            'options' => array_map(fn (array $choices): array => ['' => $this->translator->trans('work.time.report.any')] + $choices, $options) + [
                'periods' => array_combine(self::PERIODS, array_map(fn (string $period): string => $this->translator->trans('work.time.report.period.'.$period), self::PERIODS)),
                'billable' => $this->choices('billable'),
                'invoiced' => $this->choices('invoiced'),
                'groupings' => array_combine(
                    array_map(static fn (TimeGrouping $grouping): string => $grouping->value, TimeGrouping::cases()),
                    array_map(fn (TimeGrouping $grouping): string => $this->translator->trans('work.time.report.by.'.$grouping->value), TimeGrouping::cases()),
                ),
            ],
            'filtered' => [] !== array_diff_key($query, ['period' => true, 'group' => true]) || self::THIS_MONTH !== $query['period'],
            'range' => $this->translator->trans('work.time.week_range', ['from' => $filter->from->format($date), 'until' => $filter->until->format($date)]),
            'kpis' => [
                ['label' => $this->translator->trans('work.time.report.total'), 'value' => $this->format->duration($report->totalMinutes)],
                ['label' => $this->translator->trans('work.time.report.billable_total'), 'value' => $this->format->duration($report->billableMinutes)],
                ['label' => $this->translator->trans('work.time.report.unbillable_total'), 'value' => $this->format->duration($report->totalMinutes - $report->billableMinutes)],
                ['label' => $this->translator->trans('work.time.report.entries'), 'value' => (string) $report->entryCount],
            ],
            'empty' => 0 === $report->entryCount,
            'totalColumns' => $this->totalColumns($grouping),
            'totalRows' => array_map(fn (TimeTotal $total): array => $this->totalRow($total, $grouping), $report->totals),
            'totalSums' => ['minutes' => $this->format->duration($report->totalMinutes), 'billable' => $this->format->duration($report->billableMinutes), 'entries' => (string) $report->entryCount],
            'grouping' => $grouping->value,
            'entryColumns' => [
                ['key' => 'title', 'label' => $this->translator->trans('work.time.report.column.work'), 'truncate' => true],
                ['key' => 'project', 'label' => $this->translator->trans('work.task.field.project'), 'truncate' => true, 'phone' => false],
                ['key' => 'person', 'label' => $this->translator->trans('work.time.report.column.person'), 'phone' => false],
                ['key' => 'date', 'label' => $this->translator->trans('work.time.report.column.date'), 'phone' => false],
                ['key' => 'note', 'label' => $this->translator->trans('work.task.field.note'), 'truncate' => true, 'phone' => false, 'wide' => true],
                ['key' => 'billable', 'label' => $this->translator->trans('work.time.billable'), 'phone' => false],
                ['key' => 'minutes', 'label' => $this->translator->trans('work.time.report.column.time'), 'align' => 'right'],
            ],
            'entryRows' => array_map(fn (TimeEntryRow $entry): array => $this->entryRow($entry, $date), $report->entries),
            'entryCount' => $report->entryCount,
            'page' => $report->page,
            'pages' => $report->pages,
            'baseUrl' => $baseUrl,
        ]);
    }

    /**
     * Downloads every entry of the report as its filter stands, oldest first.
     */
    #[Route('/time/report/export', name: 'app_time_report_export', methods: ['POST'])]
    public function export(Request $request): StreamedResponse
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        [$filter] = $this->filterOf($request->request, $this->options());
        $name = \sprintf('%s_%s_%s.csv', $this->translator->trans('work.time.report.file'), $filter->from->format('Y-m-d'), $filter->until->format('Y-m-d'));
        // The request has ended by the time the response streams, and its locale with it.
        $locale = $request->getLocale();
        $response = new StreamedResponse(function () use ($filter, $locale): void {
            $this->csv->write($this->time->reportEntries($filter), 'php://output', $locale);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, 'time.csv'));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * The filter of the report from its query, or from the form of its download,
     * and the query that asks for it again; anything not offered is left out.
     *
     * @param InputBag<string>                                                                                      $input
     * @param array{projects: array<string, string>, clients: array<string, string>, people: array<string, string>} $options
     *
     * @return array{TimeReportFilter, array<string, string>, TimeGrouping}
     */
    private function filterOf(InputBag $input, array $options): array
    {
        $period = $input->getString('period');
        $period = \in_array($period, self::PERIODS, true) ? $period : self::THIS_MONTH;
        $from = $this->date($input->getString('from'));
        $until = $this->date($input->getString('until'));
        // Dates changed under the period they were shown for make it a custom one.
        if (self::CUSTOM !== $period && $period === $input->getString('shown') && null !== $from && null !== $until && !$this->isPreset($period, $from, $until)) {
            $period = self::CUSTOM;
        }
        if (self::CUSTOM === $period && null !== $from && null !== $until) {
            [$from, $until] = $from <= $until ? [$from, $until] : [$until, $from];
        } else {
            $period = self::CUSTOM === $period ? self::THIS_MONTH : $period;
            [$from, $until] = $this->preset($period);
        }
        $query = ['period' => $period];
        if (self::CUSTOM === $period) {
            $query += ['from' => $from->format('Y-m-d'), 'until' => $until->format('Y-m-d')];
        }
        $pick = static fn (string $name, array $offered): ?string => '' !== $input->getString($name) && isset($offered[$input->getString($name)]) ? $input->getString($name) : null;
        $project = $pick('project', $options['projects']);
        $client = $pick('client', $options['clients']);
        $person = $pick('person', $options['people']);
        $billable = $this->choice($input->getString('billable'));
        $invoiced = $this->choice($input->getString('invoiced'));
        $grouping = TimeGrouping::tryFrom($input->getString('group')) ?? TimeGrouping::Project;
        $query += array_filter([
            'project' => $project,
            'client' => $client,
            'person' => $person,
            'billable' => null === $billable ? null : ($billable ? 'yes' : 'no'),
            'invoiced' => null === $invoiced ? null : ($invoiced ? 'yes' : 'no'),
            'group' => TimeGrouping::Project === $grouping ? null : $grouping->value,
        ], static fn (?string $value): bool => null !== $value);

        return [new TimeReportFilter($from, $until, $project, $client, $person, $billable, $invoiced), $query, $grouping];
    }

    /**
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    private function preset(string $period): array
    {
        $first = $this->clock->today()->modify('first day of this month')->setTime(0, 0);
        if (self::LAST_MONTH === $period) {
            $first = $first->modify('-1 month');
        }

        return [$first, $first->modify('last day of this month')];
    }

    private function isPreset(string $period, \DateTimeImmutable $from, \DateTimeImmutable $until): bool
    {
        [$first, $last] = $this->preset($period);

        return $first->format('Y-m-d') === $from->format('Y-m-d') && $last->format('Y-m-d') === $until->format('Y-m-d');
    }

    private function date(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false !== $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    private function choice(string $value): ?bool
    {
        return match ($value) {
            'yes' => true,
            'no' => false,
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    private function choices(string $name): array
    {
        return array_combine(self::CHOICES, array_map(fn (string $choice): string => $this->translator->trans('work.time.report.'.$name.'.'.('' === $choice ? 'all' : $choice)), self::CHOICES));
    }

    /**
     * The projects the person sees, archived ones after the active ones, their
     * clients and the people of the organisation, by name.
     *
     * @return array{projects: array<string, string>, clients: array<string, string>, people: array<string, string>}
     */
    private function options(): array
    {
        $projects = $clients = $people = [];
        foreach ([false, true] as $archived) {
            foreach ($this->work->projects($archived) as $project) {
                $projects[$project->id] = $archived ? $this->translator->trans('work.time.report.archived', ['name' => $project->name]) : $project->name;
                if (null !== $project->clientId) {
                    $clients[$project->clientId] = $project->clientName ?? '';
                }
            }
        }
        asort($clients, \SORT_NATURAL | \SORT_FLAG_CASE);
        foreach ($this->people->all() as $person) {
            if (Role::Guest !== $person->role) {
                $people[$person->membershipId] = $person->name;
            }
        }
        asort($people, \SORT_NATURAL | \SORT_FLAG_CASE);

        return ['projects' => $projects, 'clients' => $clients, 'people' => $people];
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
    private function totalRow(TimeTotal $total, TimeGrouping $grouping): array
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
    private function entryRow(TimeEntryRow $entry, string $date): array
    {
        $title = $entry->taskTitle ?? $entry->projectName;
        $day = $entry->workedOn->format($date);

        return [
            'id' => $entry->id,
            'href' => null === $entry->taskId ? $this->generateUrl(ProjectController::PROJECT_ROUTE, ['id' => $entry->projectId]) : $this->generateUrl(TaskController::ROUTE, ['id' => $entry->taskId]),
            'label' => $title,
            // On a phone the line under the work says when, who and where.
            'secondary' => implode(' · ', null === $entry->taskId ? [$day, $entry->personName] : [$day, $entry->personName, $entry->projectName]),
            'cells' => [
                'title' => $title,
                'note' => $entry->note,
                'project' => $entry->projectName,
                'person' => $entry->personName,
                'date' => $day,
                'billable' => $entry->billable ? ['text' => $this->translator->trans('work.time.billable'), 'tone' => 'green'] : null,
                'minutes' => $this->format->duration((int) $entry->minutes),
            ],
        ];
    }
}
