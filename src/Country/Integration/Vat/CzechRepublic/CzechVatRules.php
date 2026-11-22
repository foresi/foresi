<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Country\Integration\Vat\CzechRepublic;

use Foresi\Country\CzechRepublic\Calendar\CzechWorkingDays;
use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Contract\VatRegistration;
use Foresi\Vat\Contract\PaymentSuggestion;
use Foresi\Vat\Contract\VatCountryRules;
use Foresi\Vat\Contract\VatPeriod;

/**
 * The Czech VAT rules (zákon o DPH, ZDPH, Act 235/2004 Coll.):
 *
 * - The period is the calendar month, or the calendar quarter for a payer
 *   who may choose it (ZDPH § 99, § 99a).
 * - The return and the payment are due by the 25th day after the period
 *   ends (ZDPH § 101 (1)); a deadline on a weekend or a public holiday moves
 *   to the next working day (daňový řád, DŘ, § 33 (4)). November 2026 is due
 *   on Monday 28 December 2026.
 * - An excess deduction (nadměrný odpočet) is refunded without a request
 *   (DŘ § 155b (3)); it is expected 30 days after the due date, moved to a
 *   working day, which nobody has confirmed yet (refund_timing).
 * - The VAT is paid to the account of the payer's tax office (finanční
 *   úřad): the prefix 705, the office's own number and the bank code 0710 of
 *   the Czech National Bank, with the digits of the DIČ as the variable
 *   symbol. No office number is kept here: the person enters their own.
 * - A quarter is not allowed in the calendar year of registration nor in
 *   the year after (ZDPH § 99a (3)): a warning only, the length is kept.
 */
final class CzechVatRules implements VatCountryRules
{
    public const string VAT_ACT = 'https://www.zakonyprolidi.cz/cs/2004-235';
    public const string TAX_CODE = 'https://www.zakonyprolidi.cz/cs/2009-280';
    public const string REFUND_SOURCE = 'https://www.pracepropravniky.cz/zakony/danovy-rad-uplne-zneni/paragraf-155b/';
    public const string PAYMENT_SOURCE = 'https://financnisprava.gov.cz/cs/dane/dane/dan-z-pridane-hodnoty';

    /** The days after the end of the period by which the return and the payment are due (ZDPH § 101 (1)). */
    public const int DUE_DAYS = 25;

    /** The days after the due date by which a refund is expected (DŘ § 155b (3), unverified). */
    public const int REFUND_DAYS = 30;

    public const string QUARTER_NOT_ALLOWED = 'quarter_not_allowed';

    /** The open points of the Czech rules, until an accountant confirms them. */
    private const array UNVERIFIED = ['refund_timing', 'refund_account', 'office_account_2026', 'late_deduction_limit', 'quarter_limit_2026', 'vat_by_tax_point_cash_basis'];

    public function country(): string
    {
        return 'CZ';
    }

    public function periodOf(\DateTimeImmutable $day, VatPeriodLength $length): VatPeriod
    {
        $month = $day->setTime(0, 0)->modify('first day of this month');
        if (VatPeriodLength::Month === $length) {
            return new VatPeriod($month->format('Y-m'), $month, $month->modify('last day of this month'), $length);
        }
        $quarter = intdiv((int) $month->format('n') - 1, 3) + 1;
        $start = $month->setDate((int) $month->format('Y'), 3 * $quarter - 2, 1);

        return new VatPeriod($start->format('Y').'-Q'.$quarter, $start, $start->modify('+2 months')->modify('last day of this month'), $length);
    }

    public function dueOn(VatPeriod $period): \DateTimeImmutable
    {
        return CzechWorkingDays::workingDayFrom($period->end->modify(\sprintf('+%d days', self::DUE_DAYS)));
    }

    public function refundExpectedOn(VatPeriod $period): \DateTimeImmutable
    {
        return CzechWorkingDays::workingDayFrom($this->dueOn($period)->modify(\sprintf('+%d days', self::REFUND_DAYS)));
    }

    public function suggestedPayment(?string $vatId): PaymentSuggestion
    {
        return new PaymentSuggestion('705-<number of your tax office>/0710', (string) preg_replace('/\D+/', '', $vatId ?? ''), self::PAYMENT_SOURCE);
    }

    public function warnings(array $stretches): array
    {
        $registeredSince = null;
        $payer = false;
        foreach ($stretches as $stretch) {
            if (VatRegistration::Registered !== $stretch->status) {
                $payer = false;

                continue;
            }
            // A run of payer stretches begins with the registration; a change of length within it does not.
            if (!$payer) {
                $registeredSince = $stretch->validFrom;
                $payer = true;
            }
            $from = $stretch->validFrom ?? $registeredSince;
            // Not before 1 January of the second year after the registration.
            if (VatPeriodLength::Quarter === $stretch->periodLength && $registeredSince instanceof \DateTimeImmutable && $from instanceof \DateTimeImmutable
                && $from->format('Y-m-d') < $registeredSince->setDate((int) $registeredSince->format('Y') + 2, 1, 1)->format('Y-m-d')) {
                return [self::QUARTER_NOT_ALLOWED];
            }
        }

        return [];
    }

    public function unverified(): array
    {
        return self::UNVERIFIED;
    }
}
