<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Country;

use Foresi\Invoicing\Application\FirmInvoicingSettings;
use Foresi\Invoicing\Application\InvoicePdf;
use Foresi\Invoicing\Application\IssuedDocumentFactory;
use Foresi\Invoicing\Application\MailVariables;
use Foresi\Invoicing\Contract\DocumentLanguage;
use Foresi\Invoicing\Contract\IssuedDocument;
use Foresi\Invoicing\Contract\TotalRounding;
use Foresi\Invoicing\Domain\MailTemplateKind;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Invoicing\Application\InvoiceTestCase;
use Foresi\Tests\Support\PdfTools;

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

    public function testEveryVariableResolvesFromTheFrozenCopies(): void
    {
        $id = $this->issue->issue($this->input())->id();
        // The contact and the firm change after the invoice was issued.
        $this->customer->rename('Renamed Customer a.s.');
        $this->firm->update('Renamed Firm s.r.o.', new RegistrationNumber('CZ', '12345687'), VatId::fromString('CZ12345687'), new Address('Other Street 9', 'Other City', '300 00', 'CZ'), VatStatus::Registered, '');
        $this->em->flush();
        $this->em->clear();

        $values = $this->variables->valuesFor($this->document($id), 'Sender Example');

        self::assertSame([
            'number' => '20260001',
            'order_number' => 'PO-1',
            'variable_symbol' => '20260001',
            'description' => 'Consulting',
            'issue_date' => '1. 11. 2026',
            'due_date' => '15. 11. 2026',
            'currency' => 'CZK',
            'document_type' => 'Faktura - daňový doklad',
            'customer_name' => 'Example Customer a.s.',
            'amount_vat' => "210,00\u{a0}Kč",
            'amount_total' => "1\u{a0}210,00\u{a0}Kč",
            'amount_net' => "1\u{a0}000,00\u{a0}Kč",
            'firm_name' => 'Example Firm s.r.o.',
            'iban' => self::IBAN,
            'firm_email' => 'office@example.com',
            'firm_phone' => '+420 111 111 111',
            'registration_number' => '12345695',
            'vat_id' => 'CZ12345695',
            'sender_name' => 'Sender Example',
        ], $values);
        self::assertSame([...array_keys($values), 'link'], $this->variables->names(), 'every variable but the link has a value');
    }

    public function testAmountTotalIsThePayableAfterRounding(): void
    {
        $document = $this->document($this->issue->issue($this->input([$this->line('10.40')]))->id());

        $values = $this->variables->valuesFor($document, 'Sender Example');

        self::assertSame(1258, $document->gross->minor());
        self::assertSame("13,00\u{a0}Kč", $values['amount_total']);
    }

    public function testAmountsAndDatesEqualThePdf(): void
    {
        $document = $this->document($this->issue->issue($this->input([$this->line('1234.56')]))->id());
        $values = $this->variables->valuesFor($document, 'Sender Example');

        $text = PdfTools::text(self::getContainer()->get(InvoicePdf::class)->render($document)->bytes);

        // The PDF prints the payable with its currency, and the columns without it.
        self::assertStringContainsString($this->plain($values['amount_total']), $text);
        foreach (['amount_net', 'amount_vat'] as $amount) {
            self::assertStringContainsString($this->plain(substr($values[$amount], 0, -\strlen("\u{a0}CZK"))), $text, $amount);
        }
        foreach (['issue_date', 'due_date'] as $date) {
            self::assertStringContainsString($values[$date], $text, $date);
        }
    }

    public function testEnglishDocumentsHaveEnglishValues(): void
    {
        $document = $this->document($this->issue->issue($this->input(overrides: ['language' => DocumentLanguage::English]))->id());

        $values = $this->variables->valuesFor($document, 'Sender Example');

        self::assertSame('15 Nov 2026', $values['due_date']);
        self::assertSame("1,210.00\u{a0}CZK", $values['amount_total']);
        self::assertSame('Invoice - tax document', $values['document_type']);
    }

    public function testReminderValuesNameTheStepTheDaysAndTheAmountDue(): void
    {
        $document = $this->document($this->issue->issue($this->input())->id());

        $values = $this->variables->valuesForReminder($document, 'Sender Example', 2, Money::of('800.50', Currency::CZK), new \DateTimeImmutable('2026-11-29'));

        self::assertSame('14', $values['days_overdue'], 'due 15 November, reminded on the 29th');
        self::assertSame('2', $values['reminder_step']);
        self::assertSame("800,50\u{a0}Kč", $values['amount_due']);
        self::assertSame('20260001', $values['number'], 'every invoice variable is there too');
        self::assertSame([...array_keys($values), 'link'], $this->variables->names(MailTemplateKind::Reminder));
    }

    public function testNoDaysAreOverdueOnTheDueDateOrBefore(): void
    {
        $document = $this->document($this->issue->issue($this->input())->id());
        $due = Money::of('800.50', Currency::CZK);

        self::assertSame('0', $this->variables->valuesForReminder($document, 'Sender Example', 1, $due, new \DateTimeImmutable('2026-11-15'))['days_overdue'], 'on the due date');
        self::assertSame('0', $this->variables->valuesForReminder($document, 'Sender Example', 1, $due, new \DateTimeImmutable('2026-11-14'))['days_overdue'], 'the day before');
        self::assertSame('1', $this->variables->valuesForReminder($document, 'Sender Example', 1, $due, new \DateTimeImmutable('2026-11-16'))['days_overdue']);
    }

    private function document(string $id): IssuedDocument
    {
        return self::getContainer()->get(IssuedDocumentFactory::class)->of($id);
    }

    private function plain(string $value): string
    {
        return str_replace("\u{a0}", ' ', $value);
    }
}
