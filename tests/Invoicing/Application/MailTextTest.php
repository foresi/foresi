<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\MailText;
use PHPUnit\Framework\TestCase;

final class MailTextTest extends TestCase
{
    public function testFillsTheTokensOnceAndKeepsTheUnknownOnes(): void
    {
        self::assertSame('Invoice {link} for {amount}', new MailText()->render('Invoice {number} for {amount}', ['number' => '{link}'], false));
    }

    public function testABodyBreaksLinesTheUnixWay(): void
    {
        self::assertSame("Dear customer,\nplease pay\nthank you\n", new MailText()->render("Dear customer,\r\nplease pay\rthank you\r\n", [], false));
    }

    public function testASubjectIsOneTrimmedLine(): void
    {
        self::assertSame('Invoice 20260001 Bcc: x@example.com', new MailText()->render(" Invoice {number}\r\nBcc: x@example.com\n", ['number' => '20260001'], true));
    }
}
