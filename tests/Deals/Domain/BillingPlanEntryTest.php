<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Deals\Domain;

use Foresi\Deals\Domain\BillingPlanEntry;
use Foresi\Deals\Domain\InvalidField;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Domain\Money;
use PHPUnit\Framework\TestCase;

/**
 * A planned invoice on its own: for more than zero, its text trimmed and at
 * most 200 characters, however many bytes they take.
 */
final class BillingPlanEntryTest extends TestCase
{
    public function testAnAmountIsMoreThanZero(): void
    {
        foreach (['0.00', '-1.00'] as $amount) {
            try {
                $this->entry($amount);
                self::fail('Expected the amount '.$amount.' to be refused.');
            } catch (InvalidField $invalid) {
                self::assertSame(['amount', 'amount_invalid'], [$invalid->field, $invalid->reason]);
            }
        }
        self::assertSame(1, $this->entry('0.01')->amount()?->minor());
    }

    public function testTheTextIsTrimmedAndAtMostTwoHundredCharacters(): void
    {
        self::assertSame('Deposit', $this->entry('1.00', "  Deposit \n")->text());
        $longest = str_repeat('č', BillingPlanEntry::MAX_TEXT);
        self::assertSame($longest, $this->entry('1.00', $longest)->text());

        try {
            $this->entry('1.00', $longest.'č');
            self::fail('Expected a text too long to be refused.');
        } catch (InvalidField $invalid) {
            self::assertSame(['text', 'text_too_long'], [$invalid->field, $invalid->reason]);
        }
    }

    private function entry(string $amount, string $text = ''): BillingPlanEntry
    {
        return BillingPlanEntry::oneOff(Id::generate(), Money::of($amount, Currency::of('CZK')), null, $text, new \DateTimeImmutable('2026-11-23'));
    }
}
