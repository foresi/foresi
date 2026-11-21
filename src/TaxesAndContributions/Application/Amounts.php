<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Application;

use Foresi\ExchangeRate\Application\ExchangeRates;
use Foresi\ExchangeRate\Domain\RateNotAvailable;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Contract\ActualAmount;
use Foresi\TaxesAndContributions\Contract\ActualAmountSource;
use Foresi\TaxesAndContributions\Contract\AmountKind;
use Foresi\TaxesAndContributions\Contract\AmountLine;
use Foresi\TaxesAndContributions\Contract\AmountSum;
use Foresi\TaxesAndContributions\Contract\AmountsView;
use Foresi\TaxesAndContributions\Contract\AmountTotals;
use Foresi\TaxesAndContributions\Contract\ProfileView;
use Foresi\TaxesAndContributions\Contract\StatusKind;
use Psr\Log\LoggerInterface;

/**
 * The actual income and costs of the sole trader on a cash basis (B2, B3),
 * read from the modules whose documents are paid. Every amount counts on the
 * day it was paid, converted to CZK at the ČNB rate of that day (unverified,
 * an open point for the accountant). The records count an amount with its VAT
 * before the day the owner became a VAT payer, and without the VAT that is
 * deducted or owed from that day on (unverified as well). Only the owner
 * reads them (B7).
 */
final readonly class Amounts
{
    /**
     * The sources the estimate expects: one that is not installed is listed as
     * unavailable, so a missing module never passes for a year without income.
     */
    public const array EXPECTED_SOURCES = ['invoicing.payments', 'purchasing.payments'];

    private const int FIRST_YEAR = 2000;

    /**
     * @param iterable<ActualAmountSource> $sources
     */
    public function __construct(
        private ProfileReads $profiles,
        private iterable $sources,
        private ExchangeRates $rates,
        private InstanceClock $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * This year from 1 January to today.
     *
     * @throws TaxesRefused owner_only
     */
    public function thisYear(): AmountsView
    {
        return $this->year((int) $this->clock->today()->format('Y'));
    }

    /**
     * The year from 1 January to today, or to 31 December of a past year.
     *
     * @throws TaxesRefused owner_only, or invalid_year for a year after this one
     */
    public function year(int $year): AmountsView
    {
        return $this->yearOf($year, $this->profiles->profile());
    }

    /**
     * The same for a profile already read: by the estimate, which reads it
     * once, or by its flows for the cash flow (ProfileReads::forEstimateFlows).
     *
     * @throws TaxesRefused invalid_year for a year after this one
     */
    public function yearOf(int $year, ProfileView $profile): AmountsView
    {
        $today = $this->clock->today();
        $from = $today->setDate($year, 1, 1);
        if ($year < self::FIRST_YEAR || $from > $today) {
            throw TaxesRefused::invalid('invalid_year', 'year', \sprintf('The year is one from %d to this year.', self::FIRST_YEAR));
        }

        return $this->between($from, min($today->setDate($year, 12, 31), $today), $profile);
    }

    private function between(\DateTimeImmutable $from, \DateTimeImmutable $to, ProfileView $profile): AmountsView
    {
        $zone = $this->clock->timezone();
        $from = new \DateTimeImmutable($from->format('Y-m-d'), $zone);
        $to = new \DateTimeImmutable($to->format('Y-m-d'), $zone);
        $unavailable = [];
        $restricted = [];
        $found = [];
        $amounts = [];
        foreach ($this->sources as $source) {
            $found[] = $source->key();
            try {
                $details = $source->mayReadDetails();
                $read = [];
                foreach ($source->paidBetween($from, $to) as $amount) {
                    $read[] = $amount;
                }
            } catch (\Throwable $failure) {
                $this->logger->error('The actual amounts of {source} could not be read.', ['source' => $source->key(), 'exception' => $failure]);
                $unavailable[] = $source->key();
                continue;
            }
            if (!$details) {
                $restricted[] = $source->key();
            }
            foreach ($read as $amount) {
                $amounts[] = [$amount, $details];
            }
        }
        foreach (self::EXPECTED_SOURCES as $expected) {
            if (!\in_array($expected, $found, true)) {
                $unavailable[] = $expected;
            }
        }

        return $this->view($from, $to, $profile, $amounts, $unavailable, $restricted);
    }

    /**
     * @param list<array{ActualAmount, bool}> $amounts     with whether the caller may see the amount itself
     * @param list<string>                    $unavailable
     * @param list<string>                    $restricted
     */
    private function view(\DateTimeImmutable $from, \DateTimeImmutable $to, ProfileView $profile, array $amounts, array $unavailable, array $restricted): AmountsView
    {
        $zero = Money::ofMinor(0, Currency::CZK);
        $rates = [];
        $missing = [];
        $unverified = [];
        $sums = [];
        $totals = [];
        foreach (AmountKind::cases() as $kind) {
            $totals[$kind->value] = new AmountTotals($zero, $zero, $zero, $zero);
        }
        $lines = [];
        foreach ($amounts as [$amount, $details]) {
            $gross = $this->czk($amount->gross, $amount->paidOn, $rates, $missing);
            if (!$gross instanceof Money) {
                continue;
            }
            $net = $this->czk($amount->net, $amount->paidOn, $rates, $missing) ?? $gross;
            $vat = $gross->minus($net);
            if (Currency::CZK !== $amount->gross->currency()) {
                $unverified[] = AmountsView::UNVERIFIED_FX_PAYMENT_DAY;
            }
            $payer = 'yes' === $profile->valueOn(StatusKind::VatPayer, $amount->paidOn)?->value;
            if ($payer) {
                $unverified[] = AmountsView::UNVERIFIED_VAT_CASH_BASIS;
            }
            $counted = $payer ? $net : $gross;
            $month = $amount->paidOn->format('Y-m');
            $sum = $sums[$month][$amount->kind->value][$amount->source] ?? new AmountSum($month, $amount->kind, $amount->source, $zero, $zero, $zero, $zero);
            $sums[$month][$amount->kind->value][$amount->source] = new AmountSum($sum->month, $sum->kind, $sum->source, $sum->gross->plus($gross), $sum->net->plus($net), $sum->vat->plus($vat), $sum->counted->plus($counted));
            $total = $totals[$amount->kind->value];
            $totals[$amount->kind->value] = new AmountTotals($total->gross->plus($gross), $total->net->plus($net), $total->vat->plus($vat), $total->counted->plus($counted));
            if ($details) {
                $lines[] = new AmountLine($amount, $gross, $net, $vat, $counted);
            }
        }
        $rows = [];
        ksort($sums);
        foreach ($sums as $kinds) {
            ksort($kinds);
            foreach ($kinds as $bySource) {
                ksort($bySource);
                array_push($rows, ...array_values($bySource));
            }
        }

        return new AmountsView(
            $from,
            $to,
            $rows,
            $totals,
            $lines,
            $this->distinct($unavailable),
            $this->distinct($restricted),
            $this->distinct($missing),
            $this->distinct($unverified),
            [AmountsView::GAP_NON_DEDUCTIBLE_COSTS, AmountsView::GAP_ASSETS],
        );
    }

    /**
     * The amount in CZK at the rate of the day it was paid; null when the
     * currency has no rate then, which is remembered and listed.
     *
     * @param array<string, array<string, array{int, string}|null>> $rates   by currency and day
     * @param list<string>                                          $missing
     */
    private function czk(Money $money, \DateTimeImmutable $day, array &$rates, array &$missing): ?Money
    {
        $currency = $money->currency()->value;
        $date = $day->format('Y-m-d');
        if (!\array_key_exists($date, $rates[$currency] ?? [])) {
            try {
                $rate = $this->rates->rateOn($currency, $day);
                $rates[$currency][$date] = [$rate->amount, $rate->rate];
            } catch (RateNotAvailable $failure) {
                $this->logger->warning('No rate of {currency} on {day} for the actual amounts.', ['currency' => $currency, 'day' => $date, 'exception' => $failure]);
                $rates[$currency][$date] = null;
            }
        }
        $known = $rates[$currency][$date];
        if (null === $known) {
            $missing[] = $currency;

            return null;
        }
        [$units, $rate] = $known;

        return Money::ofMinor($money->minor(), Currency::CZK)->multipliedBy($rate)->dividedBy((string) $units);
    }

    /**
     * @param list<string> $values
     *
     * @return list<string> each value once, in the order first seen
     */
    private function distinct(array $values): array
    {
        return array_values(array_unique($values));
    }
}
