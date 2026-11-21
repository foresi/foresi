<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Country\Integration\TaxesAndContributions\CzechRepublic;

use Foresi\TaxesAndContributions\Contract\AmountKind;
use Foresi\TaxesAndContributions\Contract\ExpenseMethod;
use Foresi\TaxesAndContributions\Contract\ReturnRoute;
use Foresi\TaxesAndContributions\Contract\SwitchAdjustment;
use Foresi\TaxesAndContributions\Contract\YearBefore;

/**
 * The adjustment of a change of the expense method (ZDP § 23 (8)). A sole
 * trader who leaves the actual costs of the tax records (daňová evidence) for
 * a lump sum (§ 7 (7)), or for the flat-rate tax (paušální daň), raises the
 * tax base of the year before by the receivables open at its end and lowers
 * it by the open payables (§ 23 (8) b) 2.); received and paid advances stay
 * out. One who leaves a lump sum for the flat-rate tax adds the receivables
 * only (§ 23 (8) b) 3.). The year before gets an additional return
 * (dodatečné přiznání), on time when it is filed and its tax paid by the
 * return deadline of the year of the change. The tax here is the change in
 * the progressive income tax of the year before, at its marginal rate; with
 * no rules for that year, the basic rate of the year of the change. Whether
 * the insurance of the year before changes too is not verified, so it is
 * left as it is. The inventory and the reserves the paragraph names are not
 * kept by Foresi.
 */
final readonly class CzechSwitchAdjustment
{
    public const string WARNING = 'switch_adjustment';
    public const string UNVERIFIED = 'switch_adjustment';
    public const string UNVERIFIED_INSURANCE = 'switch_adjustment_insurance';
    public const string UNVERIFIED_PREVIOUS_BASE = 'switch_adjustment_previous_base';
    public const string GAP_INVENTORY_AND_RESERVES = 'switch_adjustment_inventory_and_reserves';
    public const string SOURCE = Rules2026::ZDP;

    public function __construct(private CzechRuleBook $rules)
    {
    }

    /**
     * @param int|null     $previousTaxBase the tax base of the year before in minor units, null when it has no rules
     * @param list<string> $unverified
     */
    public function of(int $year, YearBefore $before, ExpenseMethod $from, string $into, ?int $previousTaxBase, ReturnRoute $route, \DateTimeZone $zone, array &$unverified): SwitchAdjustment
    {
        $receivables = $before->open->totalOf(AmountKind::Income)->counted->minor();
        // With a lump sum before, the costs were never claimed, so the payables do not count.
        $payables = ExpenseMethod::Actual === $from ? $before->open->totalOf(AmountKind::Cost)->counted->minor() : 0;
        $change = $receivables - $payables;
        if (null === $previousTaxBase) {
            $unverified[] = self::UNVERIFIED_PREVIOUS_BASE;
            $rate = $this->rules->valueOn('tax.rate', new \DateTimeImmutable(\sprintf('%04d-01-01', $year), $zone))->value;
            $tax = Czk::upToCzk(Czk::percent($change, $rate));
        } else {
            $first = new \DateTimeImmutable(\sprintf('%04d-01-01', $before->year), $zone);
            $threshold = $this->rules->valueOn('average_wage', $first)->minor() * (int) $this->rules->valueOn('tax.threshold_multiple', $first)->value;
            $adjusted = max(0, Czk::downToHundreds($previousTaxBase + $change));
            $rate = $this->rules->valueOn($adjusted > $threshold ? 'tax.higher_rate' : 'tax.rate', $first)->value;
            $tax = $this->taxOf($adjusted, $threshold, $first) - $this->taxOf($previousTaxBase, $threshold, $first);
        }

        return new SwitchAdjustment(
            $before->year,
            $from,
            $into,
            $before->open->day,
            Czk::money($receivables),
            Czk::money($payables),
            Czk::money($change),
            null === $previousTaxBase ? null : Czk::money($previousTaxBase),
            $rate,
            Czk::money($tax),
            CzechDeadlines::returnDeadline($year, $route, $zone),
            self::SOURCE,
        );
    }

    /**
     * The income tax of a base before the credits (ZDP § 16), as the
     * pipeline computes it.
     */
    private function taxOf(int $base, int $threshold, \DateTimeImmutable $first): int
    {
        return Czk::upToCzk(Czk::percent(min($base, $threshold), $this->rules->valueOn('tax.rate', $first)->value) + Czk::percent(max(0, $base - $threshold), $this->rules->valueOn('tax.higher_rate', $first)->value));
    }
}
