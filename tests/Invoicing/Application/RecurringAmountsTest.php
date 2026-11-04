<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Country\CzechRepublic\Invoicing\CzechInvoicing;
use App\Country\CzechRepublic\Invoicing\CzechSupplyCodes;
use App\Country\CzechRepublic\Invoicing\CzechVatRates;
use App\Country\CzechRepublic\Invoicing\IsdocWriter;
use App\Country\CzechRepublic\Invoicing\QrPlatba;
use App\Invoicing\Application\RecurringAmounts;
use App\Shared\Domain\Money;

/**
 * What each template of a list would charge: priced on the tax point of its own
 * next date, with the base rate of 23 % from 2027 that RateChangingCountry gives.
 */
final class RecurringAmountsTest extends RecurringTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $container = self::getContainer();
        $czech = new CzechInvoicing(new CzechVatRates(), new CzechSupplyCodes(), $container->get(IsdocWriter::class), $container->get(QrPlatba::class));
        $container->set(CzechInvoicing::class, new RateChangingCountry($czech));
    }

    public function testEachTemplateIsPricedOnItsNextDate(): void
    {
        $december = $this->save->create($this->recurringInput());
        $january = $this->save->create($this->recurringInput(overrides: ['recurrence' => $this->monthly('2027-01-01')]));
        $this->em->clear();
        $templates = [$this->templates->get($december->id()), $this->templates->get($january->id())];
        $lines = [];
        foreach ($templates as $template) {
            $lines[$template->id()] = $this->templates->lines($template);
        }

        $amounts = self::getContainer()->get(RecurringAmounts::class)->payable($templates, $lines, new \DateTimeImmutable('2026-11-02'));

        self::assertSame(
            [$december->id() => 121000, $january->id() => 123000],
            array_map(static fn (?Money $amount): ?int => $amount?->minor(), $amounts),
        );
    }
}
