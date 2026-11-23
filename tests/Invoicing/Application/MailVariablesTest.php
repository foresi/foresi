<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application;

use Foresi\Invoicing\Application\FirmInvoicingSettings;
use Foresi\Invoicing\Application\MailText;
use Foresi\Invoicing\Application\MailVariables;
use Foresi\Invoicing\Contract\TotalRounding;
use Foresi\Invoicing\Domain\MailTemplateKind;

final class MailVariablesTest extends InvoiceTestCase
{
    private MailVariables $variables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->variables = self::getContainer()->get(MailVariables::class);
        $settings = self::getContainer()->get(FirmInvoicingSettings::class);
        $row = $settings->get();
        $row->change(TotalRounding::WholeUnit, '', '', '', 'office@example.com', '+420 111 111 111', 'example.com');
        $settings->save($row);
        $this->em->flush();
    }

    public function testAValueHoldingABraceIsNotExpandedAgain(): void
    {
        $text = new MailText()->render('Invoice {number} for {customer_name}', ['number' => '{customer_name}', 'customer_name' => 'Example {number}'], false);

        self::assertSame('Invoice {customer_name} for Example {number}', $text);
    }

    public function testTheLinkStaysInPlace(): void
    {
        self::assertSame("Pay here:\n{link}", new MailText()->render("Pay here:\n{link}", ['number' => '1'], false));
    }

    public function testUnknownTokenIsNamed(): void
    {
        self::assertSame(['amount', 'Number'], $this->variables->unknownIn('Invoice {number} for {amount}, {Number}, {amount} and { spaced } {link}'));
        self::assertSame([], $this->variables->unknownIn('Invoice {number} {link}'));
    }

    public function testReminderVariablesAreUnknownInInvoiceTemplates(): void
    {
        $text = 'Invoice {number}, {days_overdue} days, step {reminder_step}, {amount_due} {link}';

        self::assertSame(['days_overdue', 'reminder_step', 'amount_due'], $this->variables->unknownIn($text));
        self::assertSame(['days_overdue', 'reminder_step', 'amount_due'], $this->variables->unknownIn($text, MailTemplateKind::Invoice));
        self::assertSame([], $this->variables->unknownIn($text, MailTemplateKind::Reminder));
        self::assertNotContains('amount_due', $this->variables->names());
        $reminder = $this->variables->names(MailTemplateKind::Reminder);
        self::assertSame([...array_slice($this->variables->names(), 0, -1), 'days_overdue', 'reminder_step', 'amount_due', 'link'], $reminder, 'the invoice set, the reminder ones, then the link');
    }

    public function testACorrectionHasTheOriginalNumberBeforeTheLink(): void
    {
        self::assertSame([...array_slice($this->variables->names(), 0, -1), 'original_number', 'link'], $this->variables->names(MailTemplateKind::CorrectionDocument), 'the invoice set, the original number, then the link once');
    }

    public function testSingleLineTurnsBreaksIntoSpaces(): void
    {
        $text = new MailText();

        self::assertSame('x Bcc: other@example.com', $text->render("x\r\nBcc: other@example.com", [], true));
        self::assertSame('Invoice x Bcc: other@example.com', $text->render('Invoice {number}', ['number' => "x\nBcc: other@example.com"], true));
        self::assertSame("a\nb", $text->render('{number}', ['number' => "a\nb"], false), 'a body keeps its lines');
    }
}
