<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Application;

use Foresi\ExchangeRate\Application\ExchangeRates;
use Foresi\Instance\Application\InstanceClock;
use Foresi\TaxesAndContributions\Application\Amounts;
use Foresi\TaxesAndContributions\Application\Estimates;
use Foresi\TaxesAndContributions\Application\ProfileReads;
use Foresi\TaxesAndContributions\Application\TaxesRefused;
use Foresi\TaxesAndContributions\Contract\ActualAmountSource;
use Foresi\Tests\TaxesAndContributions\Support\TaxesTestCase;
use Psr\Log\NullLogger;

/**
 * The estimate of a legal form no installed regime computes is a conflict.
 * The estimate by the regime of the Czech rules is in Integration/Country.
 */
final class EstimatesTest extends TaxesTestCase
{
    public function testALegalFormNoRegimeComputesIsAConflict(): void
    {
        $estimates = new Estimates(self::getContainer()->get(ProfileReads::class), $this->amounts([]), [], self::getContainer()->get(InstanceClock::class));

        try {
            $estimates->of(2026);
            self::fail('Without a regime there is no estimate.');
        } catch (TaxesRefused $refused) {
            self::assertSame('no_regime', $refused->reason());
        }
    }

    /**
     * @param list<ActualAmountSource> $sources
     */
    private function amounts(array $sources): Amounts
    {
        $container = self::getContainer();

        return new Amounts($container->get(ProfileReads::class), $sources, $container->get(ExchangeRates::class), $container->get(InstanceClock::class), new NullLogger());
    }
}
