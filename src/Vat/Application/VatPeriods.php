<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Application;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Contract\FirmVatStatuses;
use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Contract\VatRegistration;
use Foresi\Party\Contract\VatStretch;
use Foresi\Vat\Contract\DocumentVat;
use Foresi\Vat\Contract\VatCountryRules;
use Foresi\Vat\Contract\VatDocumentSource;
use Foresi\Vat\Contract\VatPeriod;
use Psr\Log\LoggerInterface;

/**
 * The VAT periods of the firm, from the first day it is a payer with a stated
 * date to the period that holds the last month asked for. Each period is the
 * calendar month or quarter of its payer stretch, clipped to the stretch: a
 * payer from 12 November 2026 has the period 2026-11 from 2026-11-12 to
 * 2026-11-30, due as the whole November is. Nothing is computed before a
 * stated date, nor without the rules of the firm's country. The amounts
 * come from the documents of the installed sources (VatCalculation).
 */
final readonly class VatPeriods
{
    /** The months after this one the range runs to when the last month is not given. */
    public const int DEFAULT_MONTHS = 12;

    /** The months after this one the range may run to at most. */
    public const int MAX_MONTHS = 24;

    /** The document sources the amounts count when their modules are installed. */
    public const array EXPECTED_SOURCES = ['invoicing.issued_vat', 'purchasing.received_vat'];

    /**
     * @param iterable<VatDocumentSource> $sources
     */
    public function __construct(
        private VatAccess $access,
        private VatRules $rules,
        private FirmVatStatuses $statuses,
        private InstanceClock $clock,
        private iterable $sources,
        private LoggerInterface $logger,
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

                return new PeriodDetail($list, $view, $documents, $restricted);
            }
        }

        throw VatRefused::notFound(\sprintf('The firm has no VAT period %s.', $key));
    }

    /**
     * The periods from the month $first (from the first period when null) to
     * the month $last, with their amounts, and the sources whose documents
     * the caller may not read.
     *
     * @return array{PeriodList, list<string>}
     *
     * @throws VatRefused not_found, or invalid with range_too_long
     */
    private function compute(?\DateTimeImmutable $first, \DateTimeImmutable $last, string $field): array
    {
        $zone = $this->clock->timezone();
        $thisMonth = $this->clock->today()->modify('first day of this month');
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

        $listed = [];
        foreach ($this->periods($rules, $segments, $end) as [$whole, $clipped]) {
            if ($first instanceof \DateTimeImmutable && $clipped->end < $first) {
                continue;
            }
            $listed[] = [$whole, $clipped];
        }
        $wholes = array_column($listed, 0);
        [$documents, $unavailable, $restricted] = [] === $wholes ? [[], [], []] : $this->documents($wholes[0]->start, $wholes[\count($wholes) - 1]->end);
        $amounts = VatCalculation::compute(array_column($listed, 1), $documents, $stretches);
        $views = [];
        foreach ($listed as $i => [$whole, $clipped]) {
            $views[] = new PeriodView($clipped, $rules->dueOn($whole), $rules->refundExpectedOn($whole), $amounts[$i]);
        }

        return [new PeriodList(null, $rules->country(), $range[0], $range[1], $views, $warnings, $rules->unverified(), $unavailable), $restricted];
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
