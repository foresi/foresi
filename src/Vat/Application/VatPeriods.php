<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Application;

use Foresi\ExchangeRate\Application\ExchangeRates;
use Foresi\ExchangeRate\Domain\ExchangeRate;
use Foresi\ExchangeRate\Domain\RateNotAvailable;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Contract\FirmVatStatuses;
use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Contract\VatRegistration;
use Foresi\Party\Contract\VatStretch;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Vat\Contract\DocumentVat;
use Foresi\Vat\Contract\PlannedVatAmount;
use Foresi\Vat\Contract\PlannedVatSource;
use Foresi\Vat\Contract\PossibleDuplicate;
use Foresi\Vat\Contract\ScheduledVat;
use Foresi\Vat\Contract\VatCountryRules;
use Foresi\Vat\Contract\VatDocumentSource;
use Foresi\Vat\Contract\VatPayment;
use Foresi\Vat\Contract\VatPaymentSource;
use Foresi\Vat\Contract\VatPeriod;
use Foresi\Vat\Contract\VatPlanSource;
use Foresi\Vat\Contract\VatScheduleSource;
use Foresi\Vat\Domain\VatSettings;
use Psr\Log\LoggerInterface;

/**
 * The VAT periods of the firm, from the first day it is a payer with a stated
 * date to the period that holds the last month asked for. Each period is the
 * calendar month or quarter of its payer stretch, clipped to the stretch: a
 * payer from 12 November 2026 has the period 2026-11 from 2026-11-12 to
 * 2026-11-30, due as the whole November is. Nothing is computed before a
 * stated date, nor without the rules of the firm's country. The amounts
 * come from the documents, the schedules and the plans of the installed
 * sources (VatCalculation); a scheduled amount in another currency is
 * converted at today's rate, as a plan is by the forecast.
 */
final readonly class VatPeriods
{
    /** The months after this one the range runs to when the last month is not given. */
    public const int DEFAULT_MONTHS = 12;

    /** The months after this one the range may run to at most. */
    public const int MAX_MONTHS = 24;

    /** The document sources the amounts count when their modules are installed. */
    public const array EXPECTED_SOURCES = ['invoicing.issued_vat', 'received-documents.received_vat'];

    /** The schedule sources the amounts count when their modules are installed. */
    public const array EXPECTED_SCHEDULE_SOURCES = ['invoicing.recurring_vat'];

    /** Listed in the unavailable sources when the bank movements could not be read at all. */
    public const string PAYMENT_SOURCE = 'cash-flow.vat_payments';

    /**
     * @param iterable<VatDocumentSource> $sources
     * @param iterable<VatScheduleSource> $schedules
     * @param iterable<VatPaymentSource>  $payments     Cash flow's, when it is installed
     * @param iterable<PlannedVatSource>  $plannedItems Cash flow's, when it is installed
     * @param iterable<VatPlanSource>     $plans        Cash flow's, when it is installed
     */
    public function __construct(
        private VatAccess $access,
        private VatRules $rules,
        private FirmVatStatuses $statuses,
        private InstanceClock $clock,
        private iterable $sources,
        private LoggerInterface $logger,
        private ExchangeRates $exchangeRates,
        private iterable $schedules = [],
        private ?VatStore $store = null,
        private iterable $payments = [],
        private iterable $plannedItems = [],
        private iterable $plans = [],
    ) {
    }

    /**
     * @param ?string $from the first month, "2026-11" or a quarter "2026-Q4"; the first period when null
     * @param ?string $to   the last month, likewise; 12 months after this one when null, at most 24
     *
     * @throws VatRefused forbidden, not_found, or invalid with from_invalid, to_invalid, range_invalid or range_too_long
     */
    public function list(?string $from, ?string $to): PeriodList
    {
        $this->access->denyUnlessStaff();
        $zone = $this->clock->timezone();
        $thisMonth = $this->clock->today()->modify('first day of this month');
        $first = null === $from ? null : $this->month($from, 'from', $zone, true);
        $last = null === $to ? $thisMonth->modify(\sprintf('+%d months', self::DEFAULT_MONTHS)) : $this->month($to, 'to', $zone, false);
        if ($first instanceof \DateTimeImmutable && $first > $last) {
            throw VatRefused::invalid('range_invalid', 'from', 'The first month is not after the last one.');
        }

        return $this->compute($first, $last, 'to')[0];
    }

    /**
     * One period by its key, such as "2026-11" or "2026-Q4", with the
     * documents behind its amounts that the caller may read.
     *
     * @throws VatRefused forbidden, not_found, or invalid with key_invalid or range_too_long
     */
    public function get(string $key): PeriodDetail
    {
        $this->access->denyUnlessStaff();
        $zone = $this->clock->timezone();
        $key = strtoupper(trim($key));
        [$list, $restricted] = $this->compute($this->month($key, 'key', $zone, true), $this->month($key, 'key', $zone, false), 'key');
        foreach ($list->periods as $view) {
            if ($view->period->key === $key) {
                $documents = array_values(array_filter($view->amounts->documents, static fn (DocumentVat $document): bool => !\in_array($document->source, $restricted, true)));
                $schedules = array_values(array_filter($view->amounts->schedules, static fn (ScheduleAmount $schedule): bool => !\in_array($schedule->schedule->source, $restricted, true)));
                $plans = array_values(array_filter($view->amounts->plans, static fn (PlannedVatAmount $plan): bool => !\in_array($plan->source, $restricted, true)));

                return new PeriodDetail($list, $view, $documents, $restricted, $schedules, $plans);
            }
        }

        throw VatRefused::notFound(\sprintf('The firm has no VAT period %s.', $key));
    }

    /**
     * The periods to the one that holds the last day, with what they still
     * expect, for the cash-flow forecast: no staff check, since Cash flow
     * checks who reads it, and no possible duplicates, which are read from
     * the forecast itself.
     *
     * @return list<PeriodView>
     *
     * @throws VatRefused not_found before the first-run setup
     */
    public function open(\DateTimeImmutable $to): array
    {
        $zone = $this->clock->timezone();
        $max = $this->clock->today()->modify('first day of this month')->modify(\sprintf('+%d months', self::MAX_MONTHS));
        $last = min(new \DateTimeImmutable($to->format('Y-m-01'), $zone), $max);

        return $this->compute(null, $last, 'to', false)[0]->periods;
    }

    /**
     * The periods from the month $first (from the first period when null) to
     * the month $last, with their amounts, what people entered for them, the
     * bank movements that pay them, and the sources whose documents the
     * caller may not read. The movements are matched over every period from
     * the first one to this month, so a period's payments never depend on
     * the range asked for.
     *
     * @return array{PeriodList, list<string>}
     *
     * @throws VatRefused not_found, or invalid with range_too_long
     */
    private function compute(?\DateTimeImmutable $first, \DateTimeImmutable $last, string $field, bool $withDuplicates = true): array
    {
        $zone = $this->clock->timezone();
        $today = $this->clock->today();
        $thisMonth = $today->modify('first day of this month');
        if ($last > $thisMonth->modify(\sprintf('+%d months', self::MAX_MONTHS))) {
            throw VatRefused::invalid('range_too_long', $field, \sprintf('The last month is at most %d months after this one.', self::MAX_MONTHS));
        }
        $end = $last->modify('last day of this month');
        $range = [$first?->format('Y-m'), $last->format('Y-m')];

        $firm = $this->access->firm();
        $rules = $this->rules->of($firm);
        if (!$rules instanceof VatCountryRules) {
            return [new PeriodList(PeriodList::COUNTRY_NOT_SUPPORTED, null, $range[0], $range[1], [], [], []), []];
        }
        $stretches = $this->statuses->stretches();
        $warnings = $rules->warnings($stretches);
        $segments = $this->payerSegments($stretches, $zone);
        if ([] === $segments) {
            $registered = array_filter($stretches, static fn (VatStretch $stretch): bool => VatRegistration::Registered === $stretch->status);

            return [new PeriodList([] === $registered ? PeriodList::NOT_A_PAYER : PeriodList::PAYER_DATE_MISSING, $rules->country(), $range[0], $range[1], [], $warnings, $rules->unverified()), []];
        }

        $all = $this->periods($rules, $segments, max($end, $thisMonth->modify('last day of this month')));
        $listed = [];
        foreach ($all as $i => [$whole, $clipped]) {
            if (($first instanceof \DateTimeImmutable && $clipped->end < $first) || $whole->start > $end) {
                continue;
            }
            $listed[] = $i;
        }
        $duplicates = $withDuplicates ? $this->duplicates() : [];
        if ([] === $listed) {
            return [new PeriodList(null, $rules->country(), $range[0], $range[1], [], $warnings, $rules->unverified(), [], PeriodList::KNOWN_GAPS, [], $duplicates), []];
        }
        $wholes = array_column($all, 0);
        [$documents, $unavailable, $restricted] = $this->documents($wholes[0]->start, $wholes[\count($wholes) - 1]->end);
        [$schedules, $unscheduled, $hidden] = $this->scheduled($wholes[0]->start, $wholes[\count($wholes) - 1]->end);
        [$plans, $unplanned, $private] = $this->planned($wholes[0]->start, $wholes[\count($wholes) - 1]->end);
        $amounts = VatCalculation::compute(array_column($all, 1), $documents, $stretches, $schedules, $plans);

        $entries = [];
        foreach ($this->store?->entries() ?? [] as $entry) {
            $entries[$entry->periodKey()] = $entry;
        }
        $settlement = [];
        foreach ($all as $i => [$whole, $clipped]) {
            $entry = $entries[$clipped->key] ?? null;
            $next = $all[$i + 1][0] ?? $rules->periodOf($whole->end->modify('+1 day'), $whole->length);
            $settlement[] = new SettlementPeriod($clipped->key, $clipped->end, $entry?->returnAmount() ?? $amounts[$i]->result, $rules->dueOn($next), $entry?->settledAt() instanceof \DateTimeImmutable);
        }
        [$payments, $failed] = $this->payments($wholes[0]->start, $today);
        [$paid, $unassigned] = VatSettlement::assign($settlement, $payments, $this->manual());

        $views = [];
        foreach ($listed as $i) {
            [$whole, $clipped] = $all[$i];
            $entry = $entries[$clipped->key] ?? null;
            $mark = $entry?->settledAt() instanceof \DateTimeImmutable ? new SettledMark($entry->settledAt(), (string) $entry->settledBy(), $entry->settledNote()) : null;
            $dueOn = $rules->dueOn($whole);
            $refundOn = $rules->refundExpectedOn($whole);
            $expected = $settlement[$i]->expected;
            $state = PeriodState::of($mark instanceof SettledMark || [] !== $paid[$clipped->key], $entry?->returnAmount() instanceof Money, PeriodView::awaited($expected, $dueOn, $refundOn), $today);
            $views[] = new PeriodView($clipped, $dueOn, $refundOn, $amounts[$i], $entry?->returnAmount(), $state, $paid[$clipped->key], $mark);
        }

        return [new PeriodList(null, $rules->country(), $range[0], $range[1], $views, $warnings, $rules->unverified(), [...$unavailable, ...$unscheduled, ...$unplanned, ...$failed], PeriodList::KNOWN_GAPS, $unassigned, $duplicates), [...$restricted, ...$hidden, ...$private]];
    }

    /**
     * The movements to and from the tax office from the first day of the
     * first period to today, and the bank sources that failed. Without the
     * office's account nothing is matched.
     *
     * @return array{list<VatPayment>, list<string>}
     */
    private function payments(\DateTimeImmutable $from, \DateTimeImmutable $today): array
    {
        $settings = $this->store?->settings();
        if (!$settings instanceof VatSettings) {
            return [[], []];
        }
        $payments = [];
        $failed = [];
        foreach ($this->payments as $source) {
            try {
                $found = $source->paymentsBetween($from, $today, $settings->officeAccount(), '' === $settings->variableSymbol() ? null : $settings->variableSymbol());
            } catch (\Throwable $failure) {
                $this->logger->error('The VAT payments could not be read from the bank.', ['exception' => $failure]);
                $failed[] = self::PAYMENT_SOURCE;

                continue;
            }
            array_push($payments, ...$found->payments);
            array_push($failed, ...$found->unavailable);
        }

        return [$payments, array_values(array_unique($failed))];
    }

    /**
     * The period of each movement a person assigned, null for no VAT payment.
     *
     * @return array<string, ?string>
     */
    private function manual(): array
    {
        $manual = [];
        foreach ($this->store?->assignments() ?? [] as $assignment) {
            $manual[$assignment->movementId()] = $assignment->periodKey();
        }

        return $manual;
    }

    /**
     * The planned items of the category vat that may count the VAT twice;
     * none when they cannot be read.
     *
     * @return list<PossibleDuplicate>
     */
    private function duplicates(): array
    {
        $items = [];
        foreach ($this->plannedItems as $source) {
            try {
                array_push($items, ...$source->activeVatItems());
            } catch (\Throwable $failure) {
                $this->logger->error('The planned VAT items could not be read.', ['exception' => $failure]);
            }
        }

        return $items;
    }

    /**
     * The documents of every installed source that count on the days from
     * $from to $to, the sources that are not installed or failed to read,
     * and those whose documents the caller may not see.
     *
     * @return array{list<DocumentVat>, list<string>, list<string>}
     */
    private function documents(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $documents = [];
        $unavailable = [];
        $restricted = [];
        $found = [];
        foreach ($this->sources as $source) {
            $found[] = $source->key();
            try {
                $details = $source->mayReadDetails();
                $read = [];
                foreach ($source->between($from, $to) as $document) {
                    $read[] = $document;
                }
            } catch (\Throwable $failure) {
                $this->logger->error('The VAT of the documents of {source} could not be read.', ['source' => $source->key(), 'exception' => $failure]);
                $unavailable[] = $source->key();

                continue;
            }
            if (!$details) {
                $restricted[] = $source->key();
            }
            array_push($documents, ...$read);
        }
        foreach (self::EXPECTED_SOURCES as $expected) {
            if (!\in_array($expected, $found, true)) {
                $unavailable[] = $expected;
            }
        }

        return [$documents, $unavailable, $restricted];
    }

    /**
     * The occurrences of every installed schedule source whose tax point
     * falls on the days from $from to $to, in CZK, the sources that are not
     * installed or failed to read, and those whose schedules the caller may
     * not see.
     *
     * @return array{list<ScheduleAmount>, list<string>, list<string>}
     */
    private function scheduled(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $today = $this->clock->today();
        $amounts = [];
        $unavailable = [];
        $restricted = [];
        $found = [];
        $rates = [];
        foreach ($this->schedules as $source) {
            $found[] = $source->key();
            try {
                $details = $source->mayReadDetails();
                $read = [];
                foreach ($source->between($from, $to, $today) as $schedule) {
                    $read[] = $schedule;
                }
            } catch (\Throwable $failure) {
                $this->logger->error('The VAT of the schedules of {source} could not be read.', ['source' => $source->key(), 'exception' => $failure]);
                $unavailable[] = $source->key();

                continue;
            }
            if (!$details) {
                $restricted[] = $source->key();
            }
            foreach ($read as $schedule) {
                $amounts[] = $this->inCzk($schedule, $today, $rates);
            }
        }
        foreach (self::EXPECTED_SCHEDULE_SOURCES as $expected) {
            if (!\in_array($expected, $found, true)) {
                $unavailable[] = $expected;
            }
        }

        return [$amounts, $unavailable, $restricted];
    }

    /**
     * The plans of every installed plan source on the days from $from to
     * $to, the sources that failed to read, and those whose plans the caller
     * may not see.
     *
     * @return array{list<PlannedVatAmount>, list<string>, list<string>}
     */
    private function planned(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $plans = [];
        $unavailable = [];
        $restricted = [];
        foreach ($this->plans as $source) {
            try {
                $details = $source->mayReadDetails();
                $read = [];
                foreach ($source->between($from, $to) as $plan) {
                    $read[] = $plan;
                }
            } catch (\Throwable $failure) {
                $this->logger->error('The VAT of the plans of {source} could not be read.', ['source' => $source->key(), 'exception' => $failure]);
                $unavailable[] = $source->key();

                continue;
            }
            if (!$details) {
                $restricted[] = $source->key();
            }
            array_push($plans, ...$read);
        }

        return [$plans, $unavailable, $restricted];
    }

    /**
     * The output of a scheduled occurrence in CZK at today's rate, each rate
     * asked once; zero, and marked, when its currency has no rate today.
     *
     * @param array<string, ExchangeRate|null> $rates the rates asked so far, by currency
     */
    private function inCzk(ScheduledVat $schedule, \DateTimeImmutable $today, array &$rates): ScheduleAmount
    {
        $output = $schedule->output;
        $currency = $output->currency();
        if (Currency::CZK === $currency) {
            return new ScheduleAmount($schedule, $output);
        }
        if (!\array_key_exists($currency->value, $rates)) {
            try {
                $rates[$currency->value] = $this->exchangeRates->rateOn($currency->value, $today);
            } catch (RateNotAvailable $failure) {
                $this->logger->warning('No rate of {currency} for the VAT of the schedules.', ['currency' => $currency->value, 'exception' => $failure]);
                $rates[$currency->value] = null;
            }
        }
        $rate = $rates[$currency->value];
        if (!$rate instanceof ExchangeRate) {
            return new ScheduleAmount($schedule, Money::ofMinor(0, Currency::CZK), true, true);
        }

        return new ScheduleAmount($schedule, Money::ofMinor($output->minor(), Currency::CZK)->multipliedBy($rate->rate)->dividedBy((string) $rate->amount), true);
    }

    /**
     * The dated stretches in which the firm is a payer, each with its first
     * and last day; the last day is null while the stretch lasts.
     *
     * @param list<VatStretch> $stretches
     *
     * @return list<array{\DateTimeImmutable, ?\DateTimeImmutable, VatPeriodLength}>
     */
    private function payerSegments(array $stretches, \DateTimeZone $zone): array
    {
        $segments = [];
        foreach ($stretches as $i => $stretch) {
            if (VatRegistration::Registered !== $stretch->status || !$stretch->validFrom instanceof \DateTimeImmutable || !$stretch->periodLength instanceof VatPeriodLength) {
                continue;
            }
            $next = $stretches[$i + 1]->validFrom ?? null;
            $segments[] = [
                $this->day($stretch->validFrom, $zone),
                $next instanceof \DateTimeImmutable ? $this->day($next, $zone)->modify('-1 day') : null,
                $stretch->periodLength,
            ];
        }

        return $segments;
    }

    /**
     * Every period of the segments up to the one that holds the last day, as
     * the whole period and the part of it in its segment. Two parts of one
     * period next to each other, such as a payer stretch that only changes
     * its length on a period's first day, would be one period, so they are
     * joined.
     *
     * @param list<array{\DateTimeImmutable, ?\DateTimeImmutable, VatPeriodLength}> $segments
     *
     * @return list<array{VatPeriod, VatPeriod}>
     */
    private function periods(VatCountryRules $rules, array $segments, \DateTimeImmutable $last): array
    {
        $periods = [];
        foreach ($segments as [$start, $end, $length]) {
            $stop = $end instanceof \DateTimeImmutable ? min($end, $last) : $last;
            $day = $start;
            while ($day <= $stop) {
                $whole = $rules->periodOf($day, $length);
                $clipped = $whole->clippedTo($start, $end ?? $whole->end);
                $day = $whole->end->modify('+1 day');
                $previous = array_key_last($periods);
                if (null !== $previous && $periods[$previous][1]->key === $whole->key && $periods[$previous][1]->end->modify('+1 day')->format('Y-m-d') === $clipped->start->format('Y-m-d')) {
                    $periods[$previous][1] = new VatPeriod($whole->key, $periods[$previous][1]->start, $clipped->end, $length);

                    continue;
                }
                $periods[] = [$whole, $clipped];
            }
        }

        return $periods;
    }

    /**
     * @throws VatRefused invalid
     */
    private function month(string $value, string $field, \DateTimeZone $zone, bool $first): \DateTimeImmutable
    {
        $value = strtoupper(trim($value));
        if (1 === preg_match('/^([0-9]{4})-(0[1-9]|1[0-2])\z/', $value, $m)) {
            return new \DateTimeImmutable(\sprintf('%s-%s-01', $m[1], $m[2]), $zone);
        }
        if (1 === preg_match('/^([0-9]{4})-Q([1-4])\z/', $value, $m)) {
            return new \DateTimeImmutable(\sprintf('%s-%02d-01', $m[1], 3 * (int) $m[2] - ($first ? 2 : 0)), $zone);
        }

        throw VatRefused::invalid($field.'_invalid', $field, \sprintf('%s is a month such as "2026-11" or a quarter such as "2026-Q4".', $field));
    }

    private function day(\DateTimeImmutable $day, \DateTimeZone $zone): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day->format('Y-m-d'), $zone);
    }
}
