<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Integration\CashFlow;

use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FoundMovement;
use Foresi\CashFlow\Contract\MovementFinder;
use Foresi\CashFlow\Contract\MovementRule;
use Foresi\TaxesAndContributions\Contract\Authority;
use Foresi\TaxesAndContributions\Contract\LevyPayment;
use Foresi\TaxesAndContributions\Contract\LevyPayments;
use Foresi\TaxesAndContributions\Contract\LevyPaymentSource;

/**
 * The bank movements that pay the levies, found by the cash flow's movement
 * finder (B4): a movement meets an authority's account and variable symbol
 * the way a planned item's match rule does (ActualFlow::meets). A document
 * never pays a levy; a movement pays at most one authority, the first it meets.
 */
final readonly class BankLevyPayments implements LevyPaymentSource
{
    public function __construct(private MovementFinder $finder)
    {
    }

    public function paymentsBetween(\DateTimeImmutable $from, \DateTimeImmutable $to, array $accounts): LevyPayments
    {
        $rules = [];
        foreach ($accounts as $account) {
            if (Authority::tryFrom($account->authority) instanceof Authority) {
                $rules[] = new MovementRule($account->authority, $account->account, '' === $account->variableSymbol ? null : $account->variableSymbol);
            }
        }
        $found = $this->finder->find($from, $to, $rules);

        return new LevyPayments(
            array_map(static fn (FoundMovement $movement): LevyPayment => new LevyPayment(Authority::from($movement->ruleKey), $movement->id, $movement->on, $movement->amount, FlowDirection::In === $movement->direction), $found->movements),
            $found->unavailable,
        );
    }
}
