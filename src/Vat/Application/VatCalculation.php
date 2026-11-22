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
 * The VAT of each period from the documents, with nothing read and nothing
 * stored. A document counts in the period whose month or quarter holds the
 * day it counts on, so the deduction month of a received document falls in
 * its quarter for a quarterly filer. It counts only when the firm is a payer
 * on its tax point: the deduction of VAT on supplies before the registration
 * is not supported, so a document from before the payer day counts nothing,
 * even when its deduction month is a payer's month. The result is the output
 * VAT minus the deductible input VAT.
 */
final class VatCalculation
{
    public const string RULE_OUTPUT = 'output VAT of the documents in the period';
    public const string RULE_INPUT = 'deductible input VAT of the documents in the period';
    public const string RULE_RESULT = 'output VAT minus deductible input VAT';

    /**
     * @param list<VatPeriod>   $periods   oldest first, clipped to the payer's stretches
     * @param list<DocumentVat> $documents
     * @param list<VatStretch>  $stretches the firm's VAT statuses, oldest first
     *
     * @return list<PeriodAmounts> one per period, in their order
     */
    public static function compute(array $periods, array $documents, array $stretches): array
    {
        /** @var array<int, list<DocumentVat>> $counted */
        $counted = [];
        foreach ($documents as $document) {
            if (!self::isPayerOn($document->taxPointOn, $stretches)) {
                continue;
            }
            foreach ($periods as $i => $period) {
                if (self::keyOf($document->countsOn, $period->length) === $period->key) {
                    $counted[$i][] = $document;

                    break;
                }
            }
        }

        $amounts = [];
        foreach ($periods as $i => $period) {
            $amounts[] = self::amounts($period, $counted[$i] ?? []);
        }

        return $amounts;
    }

    /**
     * @param list<DocumentVat> $documents
     */
    private static function amounts(VatPeriod $period, array $documents): PeriodAmounts
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

        return new PeriodAmounts(
            $period,
            $part,
            VatPart::zero(),
            VatPart::zero(),
            [
                new CalculationStep(CalculationStep::OUTPUT, $output, self::RULE_OUTPUT, $sources),
                new CalculationStep(CalculationStep::INPUT, $input, self::RULE_INPUT, $sources),
                new CalculationStep(CalculationStep::RESULT, $output->minus($input), self::RULE_RESULT, [CalculationStep::OUTPUT, CalculationStep::INPUT]),
            ],
            $documents,
            [] === $missing ? [] : [PeriodAmounts::WARNING_MISSING_CZK_VAT],
            $missing,
        );
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
