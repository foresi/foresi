<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Timesheet\Application;

use App\Identity\Application\OwnerCheck;
use App\Invoicing\Application\InvoicingCountries;
use App\Invoicing\Application\UnsupportedCountry;
use App\Invoicing\Domain\Invoice;
use App\Party\Application\LegalEntities;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Timesheet\Domain\BillableTime;
use App\Timesheet\Domain\BilledTime;
use App\Timesheet\Domain\LineGrouping;
use App\Timesheet\Domain\TimeLine;
use App\Timesheet\Domain\TimeLines;
use App\Timesheet\Domain\TimeRounding;
use App\Work\Application\ProjectRow;
use App\Work\Application\TimeEntryRow;
use App\Work\Application\TimeQueries;
use App\Work\Application\TimeReportFilter;
use App\Work\Application\WorkQueries;

/**
 * Taking time to an invoice (Harvest, Clockify): an owner picks the firm, the
 * client, some of its projects and a period, and gets the time there that is
 * billable, finished and not invoiced yet, each entry with the rate the firm
 * bills it at. The entries ticked, grouped and rounded as chosen, become the
 * lines of a new invoice form; issuing it locks them (TimeInvoiceOrigin).
 *
 * Every ticked entry needs a rate, and every rate the currency the firm
 * invoices in: a mix is refused, never converted.
 */
final readonly class TimeInvoicing
{
    public function __construct(
        private TimeQueries $time,
        private WorkQueries $work,
        private RateResolver $rates,
        private LegalEntities $firms,
        private InvoicingCountries $countries,
        private OwnerCheck $owner,
        private TimeSelections $selections,
    ) {
    }

    /**
     * Every project, archived ones included, by name: their time may still be due.
     *
     * @return list<ProjectRow>
     *
     * @throws TimeInvoicingRefused for anyone but an owner
     */
    public function projects(): array
    {
        $this->checkOwner();
        $projects = [...$this->work->projects(), ...$this->work->projects(true)];
        usort($projects, static fn (ProjectRow $a, ProjectRow $b): int => strnatcasecmp($a->name, $b->name));

        return $projects;
    }

    /**
     * The time in scope that may go on the invoice, oldest first.
     *
     * @return list<TimeCandidate>
     *
     * @throws TimeInvoicingRefused for anyone but an owner
     */
    public function candidates(TimeScope $scope): array
    {
        $this->checkOwner();
        if ([] === $scope->projectIds) {
            return [];
        }
        $firm = $this->firms->get($scope->firmId);
        $projects = array_flip($scope->projectIds);
        $rates = [];
        $candidates = [];
        foreach ($this->time->reportEntries(new TimeReportFilter($scope->from, $scope->until, clientId: $scope->clientId, billable: true, invoiced: false)) as $entry) {
            if (!isset($projects[$entry->projectId]) || $entry->running || null === $entry->minutes) {
                continue;
            }
            $pair = $entry->projectId.'/'.$entry->membershipId;
            if (!\array_key_exists($pair, $rates)) {
                $rates[$pair] = $this->rates->resolve($entry->projectId, $entry->membershipId, $firm->id())?->rate;
            }
            $candidates[] = new TimeCandidate($entry, $rates[$pair]);
        }

        return $candidates;
    }

    /**
     * The lines the ticked entries in scope make. An entry no longer in scope,
     * invoiced or changed meanwhile is not among them.
     *
     * @param list<string> $entryIds
     *
     * @return list<TimeLine>
     *
     * @throws TimeInvoicingRefused when nothing is ticked, an entry has no rate, or the rates are not all in the currency the firm invoices in
     */
    public function lines(TimeScope $scope, array $entryIds, LineGrouping $grouping, TimeRounding $rounding): array
    {
        $ticked = array_flip($entryIds);
        $times = [];
        foreach ($this->candidates($scope) as $candidate) {
            if (!isset($ticked[$candidate->entry->id])) {
                continue;
            }
            if (!$candidate->rate instanceof Money) {
                throw new TimeInvoicingRefused('rate_missing');
            }
            $times[] = $this->billable($candidate->entry, $candidate->rate);
        }
        if ([] === $times) {
            throw new TimeInvoicingRefused('nothing');
        }
        $currencies = array_values(array_unique(array_map(static fn (BillableTime $time): string => $time->rate->currency()->value, $times)));
        if (\count($currencies) > 1) {
            throw new TimeInvoicingRefused('currencies');
        }
        if (Currency::from($currencies[0]) !== $this->currencyOf($scope->firmId)) {
            throw new TimeInvoicingRefused('firm_currency');
        }
        $lines = TimeLines::of($times, $grouping, $rounding);
        if (\count($lines) > Invoice::MAX_LINES) {
            throw new TimeInvoicingRefused('too_many_lines');
        }

        return $lines;
    }

    /**
     * Keeps the lines for the invoice form to open with and to issue.
     *
     * @param list<TimeLine> $lines from lines()
     * @param string         $unit  the unit of an hour on the invoice
     * @param string         $date  the format of a day in a line's text
     *
     * @return string the reference the form carries
     *
     * @throws TimeInvoicingRefused for anyone but an owner
     */
    public function select(TimeScope $scope, array $lines, string $unit, string $date): string
    {
        $this->checkOwner();
        if ([] === $lines) {
            throw new TimeInvoicingRefused('nothing');
        }
        $selected = array_map(static fn (TimeLine $line): SelectedLine => new SelectedLine(
            $line->key,
            $line->text($date),
            $line->hours(),
            $unit,
            $line->rate,
            array_map(static fn (BilledTime $billed): SelectedEntry => new SelectedEntry($billed->time->entryId, $billed->time->fingerprint, $billed->minutes), $line->entries),
        ), $lines);

        return $this->selections->save(new TimeSelection($scope->firmId, $scope->clientId, $lines[0]->rate->currency(), $selected));
    }

    /**
     * The currency the firm invoices in.
     *
     * @throws TimeInvoicingRefused for a firm whose country has no invoicing rules
     */
    public function currencyOf(string $firmId): Currency
    {
        try {
            return $this->countries->for($this->firms->get($firmId)->address()->countryCode)->homeCurrency();
        } catch (UnsupportedCountry) {
            throw new TimeInvoicingRefused('country');
        }
    }

    private function billable(TimeEntryRow $entry, Money $rate): BillableTime
    {
        return new BillableTime($entry->id, $entry->projectId, $entry->projectName, $entry->taskId, $entry->taskTitle, $entry->membershipId, $entry->personName, $entry->workedOn, (int) $entry->minutes, $entry->note, $rate, EntryFingerprint::ofRow($entry));
    }

    /**
     * @throws TimeInvoicingRefused
     */
    private function checkOwner(): void
    {
        if (!$this->owner->isOwner()) {
            throw new TimeInvoicingRefused('not_allowed');
        }
    }
}
