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
        if (Currency::of('CZK') === $currency) {
            return $money;
        }
        if (\in_array($currency->code(), $this->missing, true)) {
            return null;
        }
        if (!isset($this->rates[$currency->code()])) {
            try {
                $this->rates[$currency->code()] = $this->exchangeRates->rateOn($currency->code(), $this->today);
            } catch (RateNotAvailable $failure) {
                $this->logger->warning('No rate of {currency} for the cash-flow forecast.', ['currency' => $currency->code(), 'exception' => $failure]);
                $this->missing[] = $currency->code();

                return null;
            }
        }
        $rate = $this->rates[$currency->code()];
        $czk = Money::ofMinor($money->minor(), Currency::of('CZK'))->multipliedBy($rate->rate);

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
