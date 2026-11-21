<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Country\Integration\TaxesAndContributions\CzechRepublic;

use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\CzechRuleBook;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Rules2026;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Rules2027;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\RuleValue;
use Foresi\TaxesAndContributions\Contract\RuleRef;
use Foresi\TaxesAndContributions\Contract\RulesNotAvailable;
use Foresi\TaxesAndContributions\Contract\RulesStatus;
use PHPUnit\Framework\TestCase;

/**
 * The dated Czech rule values: which years have them, how sure each is, and
 * that a day before the first year has none.
 */
final class CzechRuleBookTest extends TestCase
{
    public function testADayBeforeTheFirstYearHasNoRules(): void
    {
        $this->expectException(RulesNotAvailable::class);

        new CzechRuleBook()->valueOn('average_wage', new \DateTimeImmutable('2025-12-31'));
    }

    public function testAYearBeforeTheFirstHasNoRuleYear(): void
    {
        $rules = new CzechRuleBook();

        self::assertSame(2026, $rules->ruleYear(2026));
        self::assertSame(2027, $rules->ruleYear(2030));

        $this->expectException(RulesNotAvailable::class);
        $rules->ruleYear(2025);
    }

    public function testAKeyWithoutAValueIsOutOfBounds(): void
    {
        $this->expectException(\OutOfBoundsException::class);

        new CzechRuleBook()->valueOn('credit.student', new \DateTimeImmutable('2026-06-01'));
    }

    public function testEachYearSaysHowSureItsRulesAre(): void
    {
        $rules = new CzechRuleBook();

        self::assertSame(RulesStatus::Published, $rules->statusOf(2026));
        self::assertSame(RulesStatus::Derived, $rules->statusOf(2027));
        self::assertSame(RulesStatus::LatestUsed, $rules->statusOf(2028));
    }

    public function testThe2026ValuesArePublishedAndThe2027DerivedOnesUnverified(): void
    {
        foreach (Rules2026::values() as $value) {
            self::assertFalse($value->unverified, $value->key);
        }

        $unverified = [];
        foreach (Rules2027::values() as $value) {
            $unverified[$value->key] = $value->unverified;
        }
        self::assertSame([
            'average_wage' => true,
            'minimum_wage' => false,
            'social.decisive_amount' => true,
            'sickness.minimum_base' => true,
            'credit.student' => false,
            'credit.school_fees' => false,
            'credit.eet' => false,
        ], $unverified);
    }

    public function testARuleValueIsVerifiedUnlessSaidOtherwise(): void
    {
        $day = new \DateTimeImmutable('2026-01-01');
        $value = new RuleValue('minimum_wage', '22400', $day, Rules2026::MINIMUM_WAGE);

        self::assertFalse($value->unverified);
        self::assertSame(2_240_000, $value->minor());
        self::assertEquals(new RuleRef('minimum_wage', '22400', $day, Rules2026::MINIMUM_WAGE, false), $value->ref());
        self::assertFalse(new RuleRef('minimum_wage', '22400', $day, Rules2026::MINIMUM_WAGE)->unverified);
    }
}
