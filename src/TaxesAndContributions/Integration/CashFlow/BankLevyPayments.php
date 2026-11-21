<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Integration\CashFlow;

use Foresi\CashFlow\Contract\ActualFlowSource;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\TaxesAndContributions\Contract\Authority;
use Foresi\TaxesAndContributions\Contract\LevyPayment;
use Foresi\TaxesAndContributions\Contract\LevyPaymentSource;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The bank movements that pay the levies, from the actuals of the cash flow
 * (B4): a movement meets an authority's account and variable symbol the way a
 * planned item's match rule does (ActualFlow::meets). A document never pays a
 * levy; a movement pays at most one authority, the first it meets.
 */
final readonly class BankLevyPayments implements LevyPaymentSource
{
    /**
     * @param iterable<ActualFlowSource> $actuals
     */
    public function __construct(
        #[AutowireIterator(ActualFlowSource::ACTUAL_TAG)]
        private iterable $actuals,
    ) {
    }

    public function paymentsBetween(\DateTimeImmutable $from, \DateTimeImmutable $to, array $accounts): iterable
    {
        foreach ($this->actuals as $source) {
            foreach ($source->actuals($from, $to) as $actual) {
                foreach ($accounts as $account) {
                    $authority = Authority::tryFrom($account->authority);
                    if ($authority instanceof Authority && $actual->meets($account->account, '' === $account->variableSymbol ? null : $account->variableSymbol)) {
                        yield new LevyPayment($authority, $actual->subject->id, $actual->on, $actual->amount, FlowDirection::In === $actual->direction);

                        break;
                    }
                }
            }
        }
    }
}
