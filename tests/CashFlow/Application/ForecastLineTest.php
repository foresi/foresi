<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Application;

use Foresi\CashFlow\Application\ForecastLine;
use Foresi\CashFlow\Contract\ExpectedFlow;
use Foresi\CashFlow\Contract\FlowCategory;
use Foresi\CashFlow\Contract\FlowCertainty;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowFlag;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use PHPUnit\Framework\TestCase;

final class ForecastLineTest extends TestCase
{
    public function testTheDueDayIsTheSourceDayWhenAnExpectedDayMovedTheFlow(): void
    {
        $flow = $this->flow()->movedTo(new \DateTimeImmutable('2026-12-15'), FlowFlag::ExpectedDate);
        $line = new ForecastLine($flow, $flow->amount, $flow->expectedOn, new \DateTimeImmutable('2026-11-10'));

        self::assertSame('2026-11-10', $line->dueOn()->format('Y-m-d'));
    }

    public function testWithoutASourceDayTheDueDayIsTheFlowDay(): void
    {
        $flow = $this->flow();
        $line = new ForecastLine($flow, $flow->amount, $flow->expectedOn);

        self::assertSame('2026-11-10', $line->dueOn()->format('Y-m-d'));
    }

    private function flow(): ExpectedFlow
    {
        return new ExpectedFlow('test.source', new FlowSubject('invoice', 'inv-1'), FlowDirection::In, Money::of('10.00', Currency::of('CZK')), new \DateTimeImmutable('2026-11-10'), FlowCategory::Revenue, FlowCertainty::Document, 'FV-1');
    }
}
