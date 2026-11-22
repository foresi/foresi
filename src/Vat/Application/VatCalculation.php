<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Application;

use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Contract\VatRegistration;
use Foresi\Party\Contract\VatStretch;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Vat\Contract\DocumentVat;
use Foresi\Vat\Contract\VatPeriod;

/**
 * The VAT of each period from the documents and the schedules, with nothing
 * read and nothing stored. A document counts in the period whose month or quarter holds the
 * day it counts on, so the deduction month of a received document falls in
 * its quarter for a quarterly filer. It counts only when the firm is a payer
 * on its tax point: the deduction of VAT on supplies before the registration
 * is not supported, so a document from before the payer day counts nothing,
 * even when its deduction month is a payer's month. The result is the output
 * VAT minus the deductible input VAT. A scheduled occurrence counts in the
 * period of its tax point, under the same payer rule, and adds output VAT
 * only.
 */
final class VatCalculation
{
    public const string RULE_OUTPUT = 'output VAT of the documents and the schedules in the period';
    public const string RULE_INPUT = 'deductible input VAT of the documents in the period';
    public const string RULE_RESULT = 'output VAT minus deductible input VAT';

    /**
     * @param list<VatPeriod>      $periods   oldest first, clipped to the payer's stretches
     * @param list<DocumentVat>    $documents
     * @param list<VatStretch>     $stretches the firm's VAT statuses, oldest first
     * @param list<ScheduleAmount> $schedules the occurrences not issued yet, in CZK
     *
     * @return list<PeriodAmounts> one per period, in their order
     */
    public static function compute(array $periods, array $documents, array $stretches, array $schedules = []): array
    {
        /** @var array<int, list<DocumentVat>> $counted */
        $counted = [];
        foreach ($documents as $document) {
            $i = self::periodOf($document->countsOn, $document->taxPointOn, $periods, $stretches);
            if (null !== $i) {
                $counted[$i][] = $document;
            }
        }
        /** @var array<int, list<ScheduleAmount>> $scheduled */
        $scheduled = [];
        foreach ($schedules as $schedule) {
            $i = self::periodOf($schedule->schedule->taxPointOn, $schedule->schedule->taxPointOn, $periods, $stretches);
            if (null !== $i) {
                $scheduled[$i][] = $schedule;
            }
        }

        $amounts = [];
        foreach ($periods as $i => $period) {
            $amounts[] = self::amounts($period, $counted[$i] ?? [], $scheduled[$i] ?? []);
        }

        return $amounts;
    }

    /**
     * @param list<DocumentVat>    $documents
     * @param list<ScheduleAmount> $schedules
     */
    private static function amounts(VatPeriod $period, array $documents, array $schedules): PeriodAmounts
    {
        $output = Money::ofMinor(0, Currency::CZK);
        $input = $output;
        $sources = [];
        $missing = [];
        foreach ($documents as $document) {
            $output = $output->plus($document->output);
            $input = $input->plus($document->input);
            $sources[] = $document->source;
            if ($document->missingCzk) {
                $missing[] = $document->label;
            }
        }
        $sources = array_values(array_unique($sources));
        sort($sources);
        $part = new VatPart($output, $input);

        $scheduledOutput = Money::ofMinor(0, Currency::CZK);
        $scheduleSources = [];
        $warnings = [];
        foreach ($schedules as $schedule) {
            $scheduledOutput = $scheduledOutput->plus($schedule->output);
            $scheduleSources[] = $schedule->schedule->source;
            if ($schedule->missingCzk) {
                $missing[] = $schedule->schedule->label;
            }
            if ($schedule->fxToday) {
                $warnings[PeriodAmounts::WARNING_FX_TODAY] = true;
            }
        }
        $outputSources = array_unique([...$sources, ...$scheduleSources]);
        sort($outputSources);
        $total = $output->plus($scheduledOutput);
        if ([] !== $missing) {
            $warnings = [PeriodAmounts::WARNING_MISSING_CZK_VAT => true] + $warnings;
        }

        return new PeriodAmounts(
            $period,
            $part,
            new VatPart($scheduledOutput, Money::ofMinor(0, Currency::CZK)),
            VatPart::zero(),
            [
                new CalculationStep(CalculationStep::OUTPUT, $total, self::RULE_OUTPUT, $outputSources),
                new CalculationStep(CalculationStep::INPUT, $input, self::RULE_INPUT, $sources),
                new CalculationStep(CalculationStep::RESULT, $total->minus($input), self::RULE_RESULT, [CalculationStep::OUTPUT, CalculationStep::INPUT]),
            ],
            $documents,
            array_keys($warnings),
            $missing,
            $schedules,
        );
    }

    /**
     * The index of the period that holds the day an amount counts on, or null
     * when no period does or the firm is not a payer on its tax point.
     *
     * @param list<VatPeriod>  $periods
     * @param list<VatStretch> $stretches
     */
    private static function periodOf(\DateTimeImmutable $countsOn, \DateTimeImmutable $taxPointOn, array $periods, array $stretches): ?int
    {
        if (!self::isPayerOn($taxPointOn, $stretches)) {
            return null;
        }
        foreach ($periods as $i => $period) {
            if (self::keyOf($countsOn, $period->length) === $period->key) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Whether a dated payer stretch holds the day. A stretch lasts to the day
     * before the next one starts.
     *
     * @param list<VatStretch> $stretches
     */
    private static function isPayerOn(\DateTimeImmutable $day, array $stretches): bool
    {
        $on = $day->format('Y-m-d');
        foreach ($stretches as $i => $stretch) {
            $next = $stretches[$i + 1]->validFrom ?? null;
            if (VatRegistration::Registered === $stretch->status
                && $stretch->validFrom instanceof \DateTimeImmutable
                && $stretch->validFrom->format('Y-m-d') <= $on
                && (!$next instanceof \DateTimeImmutable || $on < $next->format('Y-m-d'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The key of the month ("2026-11") or the quarter ("2026-Q4") of a day.
     */
    private static function keyOf(\DateTimeImmutable $day, VatPeriodLength $length): string
    {
        return VatPeriodLength::Quarter === $length
            ? \sprintf('%s-Q%d', $day->format('Y'), intdiv($day->format('n') + 2, 3))
            : $day->format('Y-m');
    }
}
