<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure\Mail;

use App\Invoicing\Application\SendLimits;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The budgets of sending: a person may ask for 30 sends a minute, a firm for 500
 * a day. Sends of the system, the daily runs, have no person: they spend the
 * budget of their own firm only, so a large run of one firm or tenant never
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
            self::assertTrue($this->limits->take('user-1', 'firm-'.$i));
        }

        self::assertFalse($this->limits->take('user-1', 'firm-x'));
        self::assertTrue($this->limits->take('user-2', 'firm-x'), 'another person has a budget of their own');
    }

    public function testSystemSendsOfOneFirmAreNotHeldByTheMinute(): void
    {
        for ($i = 0; $i < 100; ++$i) {
            self::assertTrue($this->limits->take(null, 'firm-a'), 'send '.($i + 1));
        }
    }

    public function testSystemSendsOfFirmsInOtherTenantsDoNotShareABudget(): void
    {
        $firm = self::getContainer()->get('test.invoice_send_firm_limiter');
        $firm->create('firm-a')->consume(500);

        self::assertFalse($this->limits->take(null, 'firm-a'), 'its own day is spent');
        self::assertTrue($this->limits->take(null, 'firm-b'), 'a firm of another tenant still sends');
    }

    public function testASystemSendDoesNotSpendAPersonsBudget(): void
    {
        for ($i = 0; $i < 30; ++$i) {
            $this->limits->take(null, 'firm-a');
        }

        self::assertTrue($this->limits->take('user-1', 'firm-a'));
    }
}
