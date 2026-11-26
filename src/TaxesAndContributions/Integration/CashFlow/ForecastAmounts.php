<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Integration\CashFlow;

use Foresi\CashFlow\Contract\FlowCategory;
use Foresi\CashFlow\Contract\FlowCertainty;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\ForecastQuery;
use Foresi\CashFlow\Contract\ForecastReader;
use Foresi\CashFlow\Contract\ReadFlow;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Contract\AmountKind;
use Foresi\TaxesAndContributions\Contract\ForecastAmount;
use Foresi\TaxesAndContributions\Contract\ForecastAmountSource;
use Foresi\TaxesAndContributions\Contract\ForecastCertainty;
use Foresi\TaxesAndContributions\Contract\ForecastedAmounts;
use Foresi\TaxesAndContributions\Contract\Subject;

/**
 * The forecast of Cash flow as the year end reads it (B1), through its read
 * contract. It never asks this module's own estimate source and leaves out
 * the levies, VAT, loans and owner draws, so the estimate does not count
 * itself or money that is no income or cost of the business. Only documents,
 * schedules and plans are read; an estimate of another module is no fact.
 */
final readonly class ForecastAmounts implements ForecastAmountSource
{
    /** The key of this module's own cash-flow source, never asked. */
    public const string OWN_SOURCE = 'taxes-and-contributions.estimate';

    /** The categories that are no income or cost of the business. */
    public const array EXCLUDED_CATEGORIES = [FlowCategory::Taxes, FlowCategory::Insurance, FlowCategory::Vat, FlowCategory::Loans, FlowCategory::OwnerDraws];

    private const array CERTAINTIES = [FlowCertainty::Document, FlowCertainty::Schedule, FlowCertainty::Plan];

    public function __construct(private ForecastReader $reader)
    {
    }

    public function between(\DateTimeImmutable $from, \DateTimeImmutable $to): ForecastedAmounts
    {
        $read = $this->reader->read(new ForecastQuery($from, $to, [self::OWN_SOURCE], self::EXCLUDED_CATEGORIES, self::CERTAINTIES));
        $amounts = [];
        foreach ($read->flows as $flow) {
            $certainty = $this->certainty($flow->certainty);
            if (!$certainty instanceof ForecastCertainty) {
                continue;
            }
            [$kind, $gross] = $this->kindAndGross($flow);
            $subject = null === $flow->subject ? null : new Subject($flow->subject->type, $flow->subject->id, $flow->subject->url);
            $vat = $flow->vatCzk();
            if ($vat instanceof Money && $gross->isNegative()) {
                $vat = Money::ofMinor(-$vat->minor(), $vat->currency());
            }
            $amounts[] = new ForecastAmount($flow->source, $subject, $kind, $flow->on, $gross, $certainty, $vat);
        }

        return new ForecastedAmounts($amounts, $read->unavailableSources, $read->restrictedSources, $read->incomplete);
    }

    private function certainty(FlowCertainty $certainty): ?ForecastCertainty
    {
        return match ($certainty) {
            FlowCertainty::Document => ForecastCertainty::Document,
            FlowCertainty::Schedule => ForecastCertainty::Schedule,
            // A won deal's planned invoice is planned income until it is issued.
            FlowCertainty::Deal, FlowCertainty::Plan => ForecastCertainty::Plan,
            FlowCertainty::Estimate => null,
        };
    }

    /**
     * Revenue is income and money paid back of it is income below zero;
     * purchases are costs the same way. Any other category is income when
     * it comes in and a cost when it goes out.
     *
     * @return array{AmountKind, Money}
     */
    private function kindAndGross(ReadFlow $flow): array
    {
        $amount = $flow->amountCzk;
        $in = FlowDirection::In === $flow->direction;
        $negated = Money::ofMinor(-$amount->minor(), $amount->currency());

        return match ($flow->category) {
            FlowCategory::Revenue => [AmountKind::Income, $in ? $amount : $negated],
            FlowCategory::Purchases => [AmountKind::Cost, $in ? $negated : $amount],
            default => [$in ? AmountKind::Income : AmountKind::Cost, $amount],
        };
    }
}
