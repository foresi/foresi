<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Application;

use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Contract\ActualAmountSource;
use Foresi\TaxesAndContributions\Contract\AmountKind;
use Foresi\TaxesAndContributions\Contract\AmountSum;
use Foresi\TaxesAndContributions\Contract\AmountsView;
use Foresi\TaxesAndContributions\Contract\AmountTotals;
use Foresi\TaxesAndContributions\Contract\ForecastAmount;
use Foresi\TaxesAndContributions\Contract\ForecastAmountSource;
use Foresi\TaxesAndContributions\Contract\ForecastCertainty;
use Foresi\TaxesAndContributions\Contract\ForecastView;
use Foresi\TaxesAndContributions\Contract\ProfileView;
use Foresi\TaxesAndContributions\Contract\StatusKind;
use Foresi\TaxesAndContributions\Contract\VatShare;

/**
 * The forecast part of the year end (B1, B2): what the forecast expects from
 * today to 31 December, counted the way the actual amounts are. An open
 * document splits into net and VAT in its own ratio, which the module that
 * issued or received it gives, and counts gross before the VAT-payer day and
 * net from it. A plan with its VAT stated counts at its net, the VAT being
 * stated only on the days the firm is a payer. A schedule or a plan without
 * it counts as stated, with no VAT split, and says so; so does a document
 * whose records the caller may not read.
 */
final readonly class YearEndForecast
{
    /**
     * @param iterable<ActualAmountSource> $documents
     */
    public function __construct(private iterable $documents)
    {
    }

    /**
     * @throws \DomainException when the forecast refuses the read
     */
    public function of(ForecastAmountSource $source, ProfileView $profile, \DateTimeImmutable $from, \DateTimeImmutable $to): ForecastView
    {
        $read = $source->between($from, $to);
        $shares = $this->shares($read->amounts);
        $zero = Money::ofMinor(0, Currency::of('CZK'));
        $empty = new AmountTotals($zero, $zero, $zero, $zero);
        $totals = [];
        $byCertainty = [];
        foreach (AmountKind::cases() as $kind) {
            $totals[$kind->value] = $empty;
            foreach (ForecastCertainty::cases() as $certainty) {
                $byCertainty[$certainty->value][$kind->value] = $empty;
            }
        }
        $sums = [];
        $flags = [];
        $unverified = false;
        foreach ($read->amounts as $amount) {
            $gross = $amount->grossCzk;
            $net = $gross;
            if ($amount->vatCzk instanceof Money) {
                $net = $gross->minus($amount->vatCzk);
                $counted = $net;
                $unverified = true;
            } elseif (ForecastCertainty::Document !== $amount->certainty) {
                $flags[] = ForecastView::FLAG_PLAN_NO_VAT_SPLIT;
                $counted = $gross;
            } else {
                $share = null === $amount->subject ? null : ($shares[$amount->subject->id] ?? null);
                if (null === $amount->subject) {
                    $flags[] = ForecastView::FLAG_NO_VAT_SPLIT_RESTRICTED;
                } elseif (!$share instanceof VatShare) {
                    $flags[] = ForecastView::FLAG_NO_VAT_SPLIT_UNKNOWN;
                } else {
                    $net = $share->split($gross)[0];
                }
                $payer = 'yes' === $profile->valueOn(StatusKind::VatPayer, $amount->on)?->value;
                if ($payer) {
                    $unverified = true;
                }
                $counted = $payer ? $net : $gross;
            }
            $figures = new AmountTotals($gross, $net, $gross->minus($net), $counted);
            $totals[$amount->kind->value] = self::plus($totals[$amount->kind->value], $figures);
            $byCertainty[$amount->certainty->value][$amount->kind->value] = self::plus($byCertainty[$amount->certainty->value][$amount->kind->value], $figures);
            $month = $amount->on->format('Y-m');
            $sum = $sums[$month][$amount->kind->value][$amount->source] ?? new AmountSum($month, $amount->kind, $amount->source, $zero, $zero, $zero, $zero);
            $sums[$month][$amount->kind->value][$amount->source] = self::added($sum, $figures);
        }

        return new ForecastView(
            $from,
            $to,
            self::rows($sums),
            $totals,
            $byCertainty,
            array_values(array_unique($flags)),
            $unverified ? [AmountsView::UNVERIFIED_VAT_CASH_BASIS] : [],
            $read->unavailableSources,
            $read->restrictedSources,
            $read->incomplete,
        );
    }

    /**
     * The actual amounts to date and the forecast together, from 1 January
     * to 31 December, as a regime computes the year end from them. What the
     * forecast could not read or split stays on the ForecastView.
     */
    public static function combined(AmountsView $toDate, ForecastView $forecast): AmountsView
    {
        $sums = [];
        foreach ([...$toDate->sums, ...$forecast->sums] as $sum) {
            $known = $sums[$sum->month][$sum->kind->value][$sum->source] ?? null;
            $sums[$sum->month][$sum->kind->value][$sum->source] = null === $known ? $sum : self::added($known, new AmountTotals($sum->gross, $sum->net, $sum->vat, $sum->counted));
        }
        $totals = [];
        foreach (AmountKind::cases() as $kind) {
            $totals[$kind->value] = self::plus($toDate->totalOf($kind), $forecast->totalOf($kind));
        }

        return new AmountsView(
            $toDate->from,
            $forecast->to,
            self::rows($sums),
            $totals,
            $toDate->lines,
            $toDate->unavailableSources,
            $toDate->restrictedSources,
            $toDate->missingRates,
            $toDate->unverified,
            $toDate->knownGaps,
        );
    }

    /**
     * The ratio of each open document, asked of every module that issues or
     * receives documents; each answers for the ids it knows.
     *
     * @param list<ForecastAmount> $amounts
     *
     * @return array<string, VatShare>
     */
    private function shares(array $amounts): array
    {
        $ids = [];
        foreach ($amounts as $amount) {
            if (ForecastCertainty::Document === $amount->certainty && null !== $amount->subject && !\in_array($amount->subject->id, $ids, true)) {
                $ids[] = $amount->subject->id;
            }
        }
        if ([] === $ids) {
            return [];
        }
        $shares = [];
        foreach ($this->documents as $documents) {
            $shares += $documents->sharesOf($ids);
        }

        return $shares;
    }

    private static function plus(AmountTotals $total, AmountTotals $more): AmountTotals
    {
        return new AmountTotals($total->gross->plus($more->gross), $total->net->plus($more->net), $total->vat->plus($more->vat), $total->counted->plus($more->counted));
    }

    private static function added(AmountSum $sum, AmountTotals $more): AmountSum
    {
        return new AmountSum($sum->month, $sum->kind, $sum->source, $sum->gross->plus($more->gross), $sum->net->plus($more->net), $sum->vat->plus($more->vat), $sum->counted->plus($more->counted));
    }

    /**
     * @param array<string, array<string, array<string, AmountSum>>> $sums by month, kind and source
     *
     * @return list<AmountSum> in that order
     */
    private static function rows(array $sums): array
    {
        $rows = [];
        ksort($sums);
        foreach ($sums as $kinds) {
            ksort($kinds);
            foreach ($kinds as $bySource) {
                ksort($bySource);
                array_push($rows, ...array_values($bySource));
            }
        }

        return $rows;
    }
}
