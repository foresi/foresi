<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

use Foresi\ExchangeRate\Application\ExchangeRates;
use Foresi\ExchangeRate\Domain\ExchangeRate;
use Foresi\ExchangeRate\Domain\RateNotAvailable;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Psr\Log\LoggerInterface;

/**
 * Amounts in CZK at today's rate for one forecast or one read: each rate is
 * asked once, and a currency without one is remembered and listed.
 */
final class CzkAmounts
{
    /** @var array<string, ExchangeRate> */
    private array $rates = [];
    /** @var list<string> */
    private array $missing = [];

    public function __construct(
        private readonly ExchangeRates $exchangeRates,
        private readonly LoggerInterface $logger,
        private readonly \DateTimeImmutable $today,
    ) {
    }

    /**
     * The amount in CZK at today's rate; null when the currency has no rate.
     */
    public function of(Money $money): ?Money
    {
        $currency = $money->currency();
        if (Currency::CZK === $currency) {
            return $money;
        }
        if (\in_array($currency->value, $this->missing, true)) {
            return null;
        }
        if (!isset($this->rates[$currency->value])) {
            try {
                $this->rates[$currency->value] = $this->exchangeRates->rateOn($currency->value, $this->today);
            } catch (RateNotAvailable $failure) {
                $this->logger->warning('No rate of {currency} for the cash-flow forecast.', ['currency' => $currency->value, 'exception' => $failure]);
                $this->missing[] = $currency->value;

                return null;
            }
        }
        $rate = $this->rates[$currency->value];
        $czk = Money::ofMinor($money->minor(), Currency::CZK)->multipliedBy($rate->rate);

        return $czk->dividedBy((string) $rate->amount);
    }

    /**
     * @return list<string> the currencies without a rate today
     */
    public function missing(): array
    {
        return $this->missing;
    }
}
