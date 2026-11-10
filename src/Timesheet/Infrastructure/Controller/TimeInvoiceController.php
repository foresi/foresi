<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Timesheet\Infrastructure\Controller;

use App\Audit\Application\AuditActor;
use App\Identity\Application\OwnerCheck;
use App\Invoicing\Application\NewInvoiceDefaults;
use App\Party\Application\LegalEntities;
use App\Party\Domain\LegalEntity;
use App\Shared\Domain\Money;
use App\Tenancy\Application\TenantClock;
use App\Timesheet\Application\TimeCandidate;
use App\Timesheet\Application\TimeInvoicing;
use App\Timesheet\Application\TimeInvoicingRefused;
use App\Timesheet\Application\TimeScope;
use App\Timesheet\Domain\BillableTime;
use App\Timesheet\Domain\LineGrouping;
use App\Timesheet\Domain\TimeLine;
use App\Timesheet\Domain\TimeLines;
use App\Timesheet\Domain\TimeRounding;
use App\Work\Application\ProjectRow;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Invoice time", for an owner, from the header of a project and of the time
 * report: a dialog over the page (a page of its own without JavaScript) that
 * picks the invoicing firm, the client, its projects and the period, last
 * month unless changed, and lists the billable, finished time there not
 * invoiced yet, every entry ticked (Harvest, Clockify). A change of the firm,
 * the client, the projects or the period sends the form back for the time it
 * holds (a button without JavaScript); ticks, the grouping and the rounding
 * change the totals in place.
 *
 * Continue opens the ordinary new invoice form with the lines, which stays
 * free to edit; issuing it locks the time. An entry without a rate or a mix of
 * currencies is refused here, before any form opens.
 */
final class TimeInvoiceController extends AbstractController
{
    public const string ROUTE = 'app_time_invoice';
    public const string TOKEN = 'timesheet_invoice';

    public function __construct(
        private readonly TimeInvoicing $invoicing,
        private readonly LegalEntities $firms,
        private readonly NewInvoiceDefaults $defaults,
        private readonly OwnerCheck $owner,
        private readonly AuditActor $actor,
        private readonly TenantClock $clock,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/time/invoice', name: self::ROUTE, methods: ['GET', 'POST'])]
    public function invoice(Request $request): Response
    {
        if (!$this->owner->isOwner()) {
            throw new AccessDeniedHttpException('Only an owner invoices time.');
        }
        $firms = [];
        foreach ($this->firms->active() as $firm) {
            $firms[$firm->id()] = $firm->name();
        }
        if ([] === $firms) {
            return $this->render('timesheet/invoice.html.twig', ['blocked' => 'no_firm']);
        }
        $posted = $request->isMethod('POST');
        if ($posted && !$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $input = $posted ? $request->request : $request->query;

        $firmId = $input->getString('firm');
        $firmId = isset($firms[$firmId]) ? $firmId : $this->defaultFirm($firms);
        $all = $this->invoicing->projects();
        $clients = [];
        foreach ($all as $row) {
            if (null !== $row->clientId) {
                $clients[$row->clientId] = (string) $row->clientName;
            }
        }
        natcasesort($clients);
        $project = $request->query->getString('project');
        $projectClient = array_find($all, static fn (ProjectRow $row): bool => $row->id === $project)?->clientId;
        $clientId = $input->getString('client');
        $clientId = isset($clients[$clientId]) ? $clientId : ($projectClient ?? '');
        $projects = array_values(array_filter($all, static fn (ProjectRow $row): bool => '' !== $clientId && $row->clientId === $clientId));
        $projectIds = array_map(static fn (ProjectRow $row): string => $row->id, $projects);
        // The projects ticked stay while the client does; another client starts with all of its own.
        $sameClient = $posted && $clientId === $request->request->getString('shown_client');
        $ticked = $sameClient ? array_values(array_intersect($projectIds, $this->strings($input, 'projects'))) : (\in_array($project, $projectIds, true) ? [$project] : $projectIds);
        [$from, $until] = $this->period($input);
        $grouping = LineGrouping::tryFrom($input->getString('grouping')) ?? LineGrouping::Task;
        $rounding = TimeRounding::tryFrom($input->getString('rounding')) ?? TimeRounding::None;

        $scope = new TimeScope($firmId, $clientId, $ticked, $from, $until);
        $candidates = '' === $clientId ? [] : $this->invoicing->candidates($scope);
        // An entry listed before keeps its tick; one new to the list comes ticked.
        $listed = array_flip($this->strings($request->request, 'listed'));
        $chosen = array_flip($this->strings($request->request, 'entries'));
        $entryIds = [];
        foreach ($candidates as $candidate) {
            $id = $candidate->entry->id;
            if (!$posted || !isset($listed[$id]) || isset($chosen[$id])) {
                $entryIds[] = $id;
            }
        }

        $error = null;
        if ($posted && $request->request->has('continue')) {
            try {
                if ('' === $clientId) {
                    throw new TimeInvoicingRefused('client');
                }
                $lines = $this->invoicing->lines($scope, $entryIds, $grouping, $rounding);
                $reference = $this->invoicing->select($scope, $lines, $this->translator->trans('timesheet.invoice.unit'), $this->translator->trans('format.date'));

                return $this->redirectToRoute('app_invoice_new', ['billedWork' => $reference], Response::HTTP_SEE_OTHER);
            } catch (TimeInvoicingRefused $refused) {
                $error = $this->translator->trans('timesheet.refused.'.$refused->reason);
            }
        }

        return $this->render('timesheet/invoice.html.twig', [
            'blocked' => null,
            'action' => $this->generateUrl(self::ROUTE, '' === $project ? [] : ['project' => $project]),
            // Back to the project the dialog was opened from, else to the time report.
            'back' => null === $projectClient ? $this->generateUrl('app_time_report') : $this->generateUrl('app_project', ['id' => $project]),
            'firms' => $firms,
            'firm' => $firmId,
            'clients' => ['' => $this->translator->trans('timesheet.invoice.choose_client')] + $clients,
            'client' => $clientId,
            'projects' => array_combine($projectIds, array_map(static fn (ProjectRow $row): string => $row->name, $projects)),
            'ticked' => $ticked,
            'from' => $from->format('Y-m-d'),
            'until' => $until->format('Y-m-d'),
            'groupings' => $this->choices(LineGrouping::cases(), 'timesheet.invoice.grouping.'),
            'grouping' => $grouping->value,
            'roundings' => $this->choices(TimeRounding::cases(), 'timesheet.invoice.rounding.'),
            'rounding' => $rounding->value,
            'entries' => array_map(fn (TimeCandidate $candidate): array => $this->entryView($candidate, $entryIds), $candidates),
            'totals' => $this->totals($candidates, $entryIds, $grouping, $rounding),
            'error' => $error,
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * The firm a new invoice opens in, as on any new invoice.
     *
     * @param array<string, string> $firms names by id
     */
    private function defaultFirm(array $firms): string
    {
        $firm = $this->defaults->firmFor($this->actor->userId());

        return $firm instanceof LegalEntity && isset($firms[$firm->id()]) ? $firm->id() : (string) array_key_first($firms);
    }

    /**
     * The days typed, or last month in the organisation's zone; the earlier first.
     *
     * @param InputBag<string> $input
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    private function period(InputBag $input): array
    {
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $input->getString('from'));
        $until = \DateTimeImmutable::createFromFormat('!Y-m-d', $input->getString('until'));
        if (!$from instanceof \DateTimeImmutable || !$until instanceof \DateTimeImmutable) {
            $first = $this->clock->today()->modify('first day of last month')->setTime(0, 0);

            return [$first, $first->modify('last day of this month')];
        }

        return $from <= $until ? [$from, $until] : [$until, $from];
    }

    /**
     * @param InputBag<string> $input
     *
     * @return list<string>
     */
    private function strings(InputBag $input, string $name): array
    {
        $values = $input->all()[$name] ?? [];

        return \is_array($values) ? array_values(array_filter($values, \is_string(...))) : [];
    }

    /**
     * @param list<LineGrouping|TimeRounding> $cases
     *
     * @return array<int|string, string> labels by value; PHP keys "15" as 15
     */
    private function choices(array $cases, string $prefix): array
    {
        $choices = [];
        foreach ($cases as $case) {
            $choices[$case->value] = $this->translator->trans($prefix.$case->value);
        }

        return $choices;
    }

    /**
     * @param list<string> $entryIds the ticked entries
     *
     * @return array<string, mixed>
     */
    private function entryView(TimeCandidate $candidate, array $entryIds): array
    {
        $entry = $candidate->entry;
        $rate = $candidate->rate;
        $minutes = (int) $entry->minutes;
        $groups = [
            LineGrouping::Project->value => $entry->projectId,
            LineGrouping::Task->value => $entry->projectId.'/'.($entry->taskId ?? ''),
            LineGrouping::Person->value => $entry->projectId.'/'.$entry->membershipId,
            LineGrouping::Entry->value => $entry->id,
        ];

        return [
            'id' => $entry->id,
            'ticked' => \in_array($entry->id, $entryIds, true),
            'date' => $entry->workedOn->format($this->translator->trans('format.date')),
            'datetime' => $entry->workedOn->format('Y-m-d'),
            'title' => implode(TimeLine::SEPARATOR, array_filter([$entry->projectName, $entry->taskTitle], static fn (?string $name): bool => null !== $name)),
            'person' => $entry->personName,
            'note' => $entry->note,
            'minutes' => $minutes,
            'rate' => $rate instanceof Money ? $this->translator->trans('timesheet.invoice.per_hour', ['rate' => $rate->format($this->translator->getLocale())]) : null,
            'rateMinor' => $rate?->minor(),
            'rateCurrency' => $rate?->currency()->value,
            'rateScale' => $rate instanceof Money ? 10 ** $rate->currency()->minorUnits() : null,
            'groups' => $groups,
        ];
    }

    /**
     * The hours and the amount of the ticked time as its lines would bill it;
     * no amount while ticked time has no rate or rates are in more than one
     * currency, which Continue refuses.
     *
     * @param list<TimeCandidate> $candidates
     * @param list<string>        $entryIds   the ticked entries
     *
     * @return array{hours: string, amount: ?string}
     */
    private function totals(array $candidates, array $entryIds, LineGrouping $grouping, TimeRounding $rounding): array
    {
        $ticked = array_flip($entryIds);
        $times = [];
        $unpriced = 0;
        foreach ($candidates as $candidate) {
            $entry = $candidate->entry;
            if (!isset($ticked[$entry->id])) {
                continue;
            }
            if (!$candidate->rate instanceof Money) {
                $unpriced += $rounding->minutes((int) $entry->minutes);
                continue;
            }
            $times[] = new BillableTime($entry->id, $entry->projectId, $entry->projectName, $entry->taskId, $entry->taskTitle, $entry->membershipId, $entry->personName, $entry->workedOn, (int) $entry->minutes, $entry->note, $candidate->rate);
        }
        $lines = TimeLines::of($times, $grouping, $rounding);
        $hundredths = array_sum(array_map(static fn (TimeLine $line): int => intdiv($line->hours()->tenThousandths(), 100), $lines)) + intdiv($unpriced * 100 + 30, 60);
        $currencies = array_unique(array_map(static fn (TimeLine $line): string => $line->rate->currency()->value, $lines));
        $amount = null;
        if (0 === $unpriced && 1 === \count($currencies)) {
            $sum = array_reduce(\array_slice($lines, 1), static fn (Money $sum, TimeLine $line): Money => $sum->plus($line->amount()), $lines[0]->amount());
            $amount = $sum->format($this->translator->getLocale());
        }

        return ['hours' => $this->hours($hundredths), 'amount' => $amount];
    }

    private function hours(int $hundredths): string
    {
        $number = new \NumberFormatter($this->translator->getLocale(), \NumberFormatter::DECIMAL);
        $number->setAttribute(\NumberFormatter::FRACTION_DIGITS, 2);

        return $this->translator->trans('timesheet.invoice.hours', ['hours' => $number->format($hundredths / 100)]);
    }
}
