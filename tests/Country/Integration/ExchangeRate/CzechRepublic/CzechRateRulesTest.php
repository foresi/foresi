<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Country\Integration\ExchangeRate\CzechRepublic;

use Foresi\Country\Integration\ExchangeRate\CzechRepublic\CzechRateRules;
use Foresi\ExchangeRate\Application\RateSources;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A Czech firm converts at the ČNB or the ECB (VAT Act § 4(8) a1 and a2), the
 * ČNB by default, and both sources are installed with the Czech country module.
 */
final class CzechRateRulesTest extends KernelTestCase
{
    public function testTheCnbOrTheEcbTheCnbByDefault(): void
    {
        $rules = new CzechRateRules();

        self::assertSame(['CZ', ['cnb', 'ecb'], 'cnb'], [$rules->country(), $rules->allowedSources(), $rules->defaultSource()]);
    }

    public function testBothSourcesAreInstalledTheCnbFirst(): void
    {
        self::bootKernel();

        self::assertSame(['cnb', 'ecb'], array_slice(array_keys(self::getContainer()->get(RateSources::class)->installed()), 0, 2));
    }
}
