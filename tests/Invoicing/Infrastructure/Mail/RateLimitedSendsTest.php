<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Infrastructure\Mail;

use Foresi\Invoicing\Application\SendLimits;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The budgets of sending: a person may ask for 30 sends a minute, a firm for 500
 * a day. Sends of the system, the daily runs, have no person: they spend the
 * budget of their own firm only, so a large run of one firm never
 * holds back another's.
 */
final class RateLimitedSendsTest extends KernelTestCase
{
    private SendLimits $limits;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->limits = self::getContainer()->get(SendLimits::class);
    }

    public function testAPersonHasThirtyAMinute(): void
    {
        for ($i = 0; $i < 30; ++$i) {
            self::assertTrue($this->limits->take('user-1'));
        }

        self::assertFalse($this->limits->take('user-1'));
        self::assertTrue($this->limits->take('user-2'), 'another person has a budget of their own');
    }

    public function testSystemSendsAreNotHeldByTheMinute(): void
    {
        for ($i = 0; $i < 100; ++$i) {
            self::assertTrue($this->limits->take(null), 'send '.($i + 1));
        }
    }

    public function testASpentDayStopsEveryone(): void
    {
        $day = self::getContainer()->get('test.invoice_send_firm_limiter');
        $day->create('instance')->consume(500);

        self::assertFalse($this->limits->take(null), 'the day is spent');
        self::assertFalse($this->limits->take('user-1'), 'the day is spent for a person too');
    }

    public function testTheSystemLeavesAFifthOfTheDayToPeople(): void
    {
        $day = self::getContainer()->get('test.invoice_send_firm_limiter');
        $day->create('instance')->consume(399);

        self::assertTrue($this->limits->take(null), 'the 400th of 500 is still the system\'s');
        self::assertFalse($this->limits->take(null), 'the last 100 are left to people');
        self::assertSame(100, $day->create('instance')->consume(0)->getRemainingTokens(), 'a refused system send spends nothing');
        self::assertTrue($this->limits->take('user-1'), 'a person still sends');
    }

    public function testASystemSendDoesNotSpendAPersonsBudget(): void
    {
        for ($i = 0; $i < 30; ++$i) {
            $this->limits->take(null);
        }

        self::assertTrue($this->limits->take('user-1'));
    }
}
