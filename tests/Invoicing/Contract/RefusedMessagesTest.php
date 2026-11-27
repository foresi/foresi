<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Contract;

use Foresi\Invoicing\Contract\BankPaymentRefused;
use Foresi\Invoicing\Contract\BilledWorkRefused;
use PHPUnit\Framework\TestCase;

/**
 * What the refusals Invoicing gives other modules say in their message, for the logs.
 */
final class RefusedMessagesTest extends TestCase
{
    public function testABankPaymentRefusalNamesItsReason(): void
    {
        self::assertSame('The bank payment was refused: trashed.', new BankPaymentRefused(BankPaymentRefused::TRASHED, null)->getMessage());
    }

    public function testABilledWorkRefusalSaysItsProblem(): void
    {
        self::assertSame('The work is invoiced already.', new BilledWorkRefused('The work is invoiced already.')->getMessage());
    }
}
