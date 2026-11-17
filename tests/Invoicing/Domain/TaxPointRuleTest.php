<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Domain;

use App\Invoicing\Domain\TaxPointRule;
use PHPUnit\Framework\TestCase;

final class TaxPointRuleTest extends TestCase
{
    public function testTaxPointLastDayOfMonth(): void
    {
        self::assertSame('2027-02-28', $this->day(TaxPointRule::LastDayOfMonth->for(new \DateTimeImmutable('2027-02-01'))));
        self::assertSame('2028-02-29', $this->day(TaxPointRule::LastDayOfMonth->for(new \DateTimeImmutable('2028-02-29'))));
        self::assertSame('2027-12-31', $this->day(TaxPointRule::LastDayOfMonth->for(new \DateTimeImmutable('2027-12-15'))));
        self::assertSame('2027-04-30', $this->day(TaxPointRule::LastDayOfMonth->for(new \DateTimeImmutable('2027-04-30'))));
    }

    public function testTaxPointIssueDate(): void
    {
        $issued = new \DateTimeImmutable('2027-02-01 13:00', new \DateTimeZone('Europe/Prague'));

        self::assertSame('2027-02-01 00:00:00 Europe/Prague', TaxPointRule::IssueDate->for($issued)->format('Y-m-d H:i:s e'));
        self::assertSame('2027-02-28 00:00:00 Europe/Prague', TaxPointRule::LastDayOfMonth->for($issued)->format('Y-m-d H:i:s e'));
    }

    private function day(\DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d');
    }
}
