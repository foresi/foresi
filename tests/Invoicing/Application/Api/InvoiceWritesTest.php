<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application\Api;

use Foresi\Invoicing\Application\AllocationRequest;
use Foresi\Invoicing\Application\Api\CreateInvoiceInput;
use Foresi\Invoicing\Application\Api\InvoiceWrites;
use Foresi\Invoicing\Application\Api\InvoicingRefused;
use Foresi\Invoicing\Application\Api\RestoreInvoiceInput;
use Foresi\Invoicing\Application\Api\TrashInvoiceInput;
use Foresi\Invoicing\Application\Api\UpdateInvoiceInput;
use Foresi\Invoicing\Application\IssueProblem;
use Foresi\Invoicing\Application\IssueRefused;
use Foresi\Invoicing\Application\PaymentRequest;
use Foresi\Invoicing\Application\RecordPayment;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\InvoiceLine;
use Foresi\Invoicing\Domain\PaymentSource;
use Foresi\Invoicing\Domain\VariableSymbol;
use Foresi\Party\Domain\DocumentLanguage;
use Foresi\Party\Domain\InvoicingDefaults;
use Foresi\Party\Domain\PaymentMethod;
use Foresi\Shared\Application\RefusalKind;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Invoicing\Application\Query\InvoicingReadTestCase;
use Foresi\Tests\Party\Support\FirmAccounts;

/**
 * The invoice writes of the API and MCP: what is given is stored as given, what
 * is left out takes the defaults of the form or what the invoice has, and what
 * cannot be read is refused with the field it came from.
 */
final class InvoiceWritesTest extends InvoicingReadTestCase
{
    private const string OTHER_IBAN = 'CZ0708000000001234567890';

    private InvoiceWrites $writes;

    protected function setUp(): void
    {
        parent::setUp();
        FirmAccounts::add($this->em, self::OTHER_IBAN);
        $this->em->flush();
        $this->writes = self::getContainer()->get(InvoiceWrites::class);
    }

    public function testEveryFieldGivenIsIssuedAsGiven(): void
    {
        $invoice = $this->stored($this->writes->create(new CreateInvoiceInput(
            customerId: strtoupper($this->customer->id()),
            lines: [
                ['text' => '  Design  ', 'unit_price' => '1000.00', 'quantity' => '2', 'unit' => 'h', 'price_type' => 'with_vat', 'vat' => 'reduced', 'discount' => '10'],
                ['text' => 'Training', 'unit_price' => '50.00', 'vat' => 'exempt'],
            ],
            seriesId: $this->series->id(),
            number: '  OLD-2026-0042  ',
            issueDate: '2026-11-02',
            taxPointDate: '2026-11-03',
            dueDate: '2026-12-01',
            currency: 'CZK',
            paymentMethod: 'bank_transfer',
            bankAccount: self::OTHER_IBAN,
            variableSymbol: '555',
            constantSymbol: '0008',
            orderNumber: 'PO-3',
            description: 'Spring work',
            textBefore: 'Hello',
            textAfter: 'Thanks',
            language: 'en',
        )));

        self::assertSame('OLD-2026-0042', $invoice->number());
        self::assertSame($this->customer->id(), $invoice->customerParty()->id());
        self::assertSame(['2026-11-02', '2026-11-03', '2026-12-01'], [$invoice->issueDate()->format('Y-m-d'), $invoice->taxPointDate()?->format('Y-m-d'), $invoice->dueDate()->format('Y-m-d')]);
        self::assertSame(self::OTHER_IBAN, $invoice->bankAccount());
        self::assertSame(['555', '0008', 'PO-3'], [$invoice->variableSymbol(), $invoice->constantSymbol(), $invoice->orderNumber()]);
        self::assertSame(['Spring work', 'Hello', 'Thanks'], [$invoice->description(), $invoice->textBefore(), $invoice->textAfter()]);
        self::assertSame(DocumentLanguage::English, $invoice->language());

        $lines = $this->linesOf($invoice);
        self::assertCount(2, $lines);
        [$design, $training] = $lines;
        self::assertSame(['Design', '2.0000', 'h', PriceType::WithVat, '10.00'], [$design->text(), $design->quantity()->toDecimal(), $design->unit(), $design->priceType(), $design->discount()->decimal()]);
        self::assertSame([TaxTreatment::Standard, VatRateKind::Reduced], [$design->treatment(), $design->rateKind()]);
        self::assertSame([TaxTreatment::Exempt, null], [$training->treatment(), $training->rateKind()]);
    }

    public function testWhatIsLeftOutTakesTheDefaultsOfTheFormForTheCustomer(): void
    {
        $this->customer->changeInvoicingDefaults(new InvoicingDefaults(30, Currency::CZK, DocumentLanguage::English, PaymentMethod::BankTransfer, true));
        $this->em->flush();

        $invoice = $this->stored($this->writes->create(new CreateInvoiceInput(
            customerId: $this->customer->id(),
            lines: [['text' => 'Work', 'unit_price' => '100.00', 'supply_code' => '4']],
            issueDate: '2026-11-02',
        )));

        self::assertSame('2026-11-02', $invoice->taxPointDate()?->format('Y-m-d'));
        self::assertSame('2026-12-02', $invoice->dueDate()->format('Y-m-d'), 'The due days of the customer.');
        self::assertSame(DocumentLanguage::English, $invoice->language());
        self::assertSame('Work', $invoice->description(), 'The text of the first line.');
        self::assertSame(VariableSymbol::fromNumber($invoice->number()), $invoice->variableSymbol(), 'The symbol follows the number.');
        [$line] = $this->linesOf($invoice);
        self::assertSame(['1.0000', '', PriceType::WithoutVat, '0.00'], [$line->quantity()->toDecimal(), $line->unit(), $line->priceType(), $line->discount()->decimal()]);
        self::assertSame([TaxTreatment::ReverseCharge, null, '4'], [$line->treatment(), $line->rateKind(), $line->supplyCode()]);
    }

    public function testTheSecondReducedRateIsTheRateOfThatName(): void
    {
        // The Czech Republic has had one reduced rate since 2024, so a line of the second is refused for it.
        try {
            $this->created(lines: [['text' => 'Hosting', 'unit_price' => '100.00', 'vat' => 'second_reduced']]);
            self::fail('A line of the second reduced rate was issued.');
        } catch (IssueRefused $refused) {
            self::assertSame(['invoice.problem.line_rate_missing'], array_map(static fn (IssueProblem $problem): string => $problem->key, $refused->problems));
        }
    }

    public function testAnEmptySupplyCodeIsNone(): void
    {
        $invoice = $this->stored($this->writes->create(new CreateInvoiceInput(
            customerId: $this->customer->id(),
            lines: [['text' => 'Work', 'unit_price' => '100.00', 'vat' => 'reverse_charge', 'supply_code' => '']],
            issueDate: '2026-11-02',
        )));

        [$line] = $this->linesOf($invoice);
        self::assertSame([TaxTreatment::ReverseCharge, null], [$line->treatment(), $line->supplyCode()]);
    }

    public function testAChangeTakesWhatIsGivenAndKeepsTheRest(): void
    {
        $id = $this->created(taxPointDate: '2026-11-03');

        $this->writes->update(new UpdateInvoiceInput(invoiceId: $id, orderNumber: 'PO-9'));
        $kept = $this->stored($id);
        self::assertSame('PO-9', $kept->orderNumber());
        self::assertSame(['2026-11-02', '2026-11-03'], [$kept->issueDate()->format('Y-m-d'), $kept->taxPointDate()?->format('Y-m-d')]);
        self::assertSame(['Work', 'h'], [$this->linesOf($kept)[0]->text(), $this->linesOf($kept)[0]->unit()]);

        $other = $this->customer();
        $this->em->flush();
        $this->writes->update(new UpdateInvoiceInput(
            invoiceId: $id,
            customerId: $other->id(),
            issueDate: '2026-11-04',
            taxPointDate: '2026-11-05',
            bankAccount: self::OTHER_IBAN,
            constantSymbol: '0008',
            description: 'Changed',
            textBefore: 'Above',
            textAfter: 'Below',
        ));
        $changed = $this->stored($id);
        self::assertSame($other->id(), $changed->customerParty()->id());
        self::assertSame(['2026-11-04', '2026-11-05'], [$changed->issueDate()->format('Y-m-d'), $changed->taxPointDate()?->format('Y-m-d')]);
        self::assertSame([self::OTHER_IBAN, '0008'], [$changed->bankAccount(), $changed->constantSymbol()]);
        self::assertSame(['Changed', 'Above', 'Below'], [$changed->description(), $changed->textBefore(), $changed->textAfter()]);
    }

    public function testASymbolThatFollowedTheNumberKeepsFollowingIt(): void
    {
        $id = $this->created();

        $this->writes->update(new UpdateInvoiceInput(invoiceId: $id, number: 'NEW-0077'));
        self::assertSame(VariableSymbol::fromNumber('NEW-0077'), $this->stored($id)->variableSymbol());

        $this->writes->update(new UpdateInvoiceInput(invoiceId: $id, variableSymbol: '999'));
        self::assertSame('999', $this->stored($id)->variableSymbol());

        $this->writes->update(new UpdateInvoiceInput(invoiceId: $id, number: 'NEW-0078'));
        $renumbered = $this->stored($id);
        self::assertSame('NEW-0078', $renumbered->number());
        self::assertSame('999', $renumbered->variableSymbol(), 'A symbol of its own stays.');
    }

    public function testNewLinesOfAChangeTakeTheReverseChargeOfTheCustomer(): void
    {
        $id = $this->created();
        $this->customer->changeInvoicingDefaults(new InvoicingDefaults(14, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, true));
        $this->em->flush();

        $this->writes->update(new UpdateInvoiceInput(invoiceId: $id, lines: [['text' => 'Review', 'unit_price' => '500.00']]));

        [$line] = $this->linesOf($this->stored($id));
        self::assertSame(['Review', TaxTreatment::ReverseCharge], [$line->text(), $line->treatment()]);
    }

    public function testALineThatCannotBeReadIsRefusedByItsField(): void
    {
        $unknown = $this->refusedLine(['text' => 'Work', 'unit_price' => '100.00', 'colour' => 'red']);
        self::assertSame([InvoicingRefused::LINE, 'lines[1].colour'], [$unknown->reason(), $unknown->field()]);

        $number = $this->refusedLine(['text' => 'Work', 'unit_price' => 100]);
        self::assertSame([InvoicingRefused::LINE, 'lines[1].unit_price'], [$number->reason(), $number->field()]);

        $priceless = $this->refusedLine(['text' => 'Work']);
        self::assertSame([InvoicingRefused::LINE, 'lines[1].unit_price'], [$priceless->reason(), $priceless->field()]);
    }

    public function testValuesAreReadWithoutTheSpacesAround(): void
    {
        $invoice = $this->stored($this->writes->create(new CreateInvoiceInput(
            customerId: $this->customer->id(),
            lines: [['text' => 'Work', 'unit_price' => ' 1000.00 ', 'quantity' => ' 2 ']],
            number: '   ',
            issueDate: '2026-11-02',
        )));

        self::assertNotSame('', trim($invoice->number()), 'A blank number is the next one of the series.');
        self::assertSame(200_000, $this->linesOf($invoice)[0]->net()->minor());
    }

    public function testAnUnknownCurrencyIsRefused(): void
    {
        $refused = $this->refused(fn (): string => $this->writes->create(new CreateInvoiceInput(
            customerId: $this->customer->id(),
            lines: [['text' => 'Work', 'unit_price' => '100.00']],
            issueDate: '2026-11-02',
            currency: 'XXX',
        )));

        self::assertSame([InvoicingRefused::CURRENCY, 'currency'], [$refused->reason(), $refused->field()]);
    }

    public function testAnUnknownCustomerOfAChangeIsTheOnlyProblem(): void
    {
        $id = $this->created();

        try {
            $this->writes->update(new UpdateInvoiceInput(invoiceId: $id, customerId: '01940000-0000-7000-8000-000000000001', lines: [['text' => 'Review', 'unit_price' => '500.00']]));
            self::fail('A change to an unknown customer was saved.');
        } catch (IssueRefused $refused) {
            self::assertSame(['invoice.problem.customer_unknown'], array_map(static fn (IssueProblem $problem): string => $problem->key, $refused->problems), 'Its lines take the VAT of no customer.');
        }
    }

    public function testTheTrashRefusesWhatTheUseCaseRefuses(): void
    {
        $id = $this->created();

        $reasonless = $this->refused(fn (): string => $this->writes->trash(new TrashInvoiceInput($id, '')));
        self::assertSame([InvoicingRefused::REASON, 'reason', RefusalKind::Invalid], [$reasonless->reason(), $reasonless->field(), $reasonless->kind()]);

        $this->writes->trash(new TrashInvoiceInput($id, 'Issued by mistake.'));
        $again = $this->refused(fn (): string => $this->writes->trash(new TrashInvoiceInput($id, 'Issued by mistake.')));
        self::assertSame([InvoicingRefused::NOT_TRASHABLE, RefusalKind::Conflict], [$again->reason(), $again->kind()]);
        self::assertSame($id, $this->writes->restore(new RestoreInvoiceInput($id)));
        self::assertFalse($this->stored($id)->isTrashed());

        $money = Money::of('1210.00', Currency::CZK);
        self::getContainer()->get(RecordPayment::class)->record(new PaymentRequest(new \DateTimeImmutable('2026-11-10'), $money, PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', [new AllocationRequest($id, $money)]));
        $this->em->flush();
        $paid = $this->refused(fn (): string => $this->writes->trash(new TrashInvoiceInput($id, 'Issued by mistake.')));
        self::assertSame([InvoicingRefused::HAS_PAYMENTS, RefusalKind::Conflict], [$paid->reason(), $paid->kind()]);
    }

    public function testAGuestWritesNothing(): void
    {
        $id = $this->created();
        $this->signIn($this->guest);

        foreach ([
            fn (): string => $this->created(),
            fn (): string => $this->writes->update(new UpdateInvoiceInput(invoiceId: $id, orderNumber: 'PO-9')),
            fn (): string => $this->writes->trash(new TrashInvoiceInput($id, 'Issued by mistake.')),
            fn (): string => $this->writes->restore(new RestoreInvoiceInput($id)),
        ] as $write) {
            self::assertSame(RefusalKind::Forbidden, $this->refused($write)->kind());
        }
        $this->signIn($this->owner);
        $untouched = $this->stored($id);
        self::assertSame('', $untouched->orderNumber());
        self::assertFalse($untouched->isTrashed());
    }

    public function testTheProblemsOfTheRulesAreNamedAsTheApiNamesThem(): void
    {
        $refused = InvoicingRefused::of(new IssueRefused([
            new IssueProblem('lines.2.price', 'invoice.problem.price_invalid'),
            new IssueProblem('customer', 'invoice.problem.customer_unknown'),
        ]), static fn (IssueProblem $problem): string => 'invoice.problem.price_invalid' === $problem->key ? 'The price is wrong.' : 'The customer is unknown');

        self::assertSame(['price_invalid', 'lines[2].unit_price', RefusalKind::Invalid], [$refused->reason(), $refused->field(), $refused->kind()]);
        self::assertSame(['problems' => ['lines[2].unit_price: price_invalid', 'customer_id: customer_unknown']], $refused->details());
        self::assertSame('The price is wrong. The customer is unknown.', $refused->getMessage());

        $text = InvoicingRefused::of(new IssueRefused([new IssueProblem('lines.0.text', 'invoice.problem.line_text_missing')]), static fn (IssueProblem $problem): string => '');
        self::assertSame('lines[0].text', $text->field(), 'A field of a line the API names alike keeps its name.');

        $none = InvoicingRefused::of(new IssueRefused([]), static fn (IssueProblem $problem): string => '');
        self::assertSame(['invalid', null, []], [$none->reason(), $none->field(), $none->details()]);

        foreach (['xlines.0.price', 'lines.0.price.extra', "lines.0.price\n"] as $field) {
            $odd = InvoicingRefused::of(new IssueRefused([new IssueProblem($field, 'invoice.problem.price_invalid')]), static fn (IssueProblem $problem): string => '');
            self::assertSame($field, $odd->field(), 'Only lines.N.field is a field of a line.');
        }
    }

    /**
     * @param list<array<string, mixed>>|null $lines
     */
    private function created(?string $taxPointDate = null, ?array $lines = null): string
    {
        return $this->writes->create(new CreateInvoiceInput(
            customerId: $this->customer->id(),
            lines: $lines ?? [['text' => 'Work', 'unit_price' => '1000.00', 'unit' => 'h']],
            issueDate: '2026-11-02',
            taxPointDate: $taxPointDate,
        ));
    }

    /**
     * The refusal of an invoice whose second line is this one.
     *
     * @param array<string, mixed> $line
     */
    private function refusedLine(array $line): InvoicingRefused
    {
        return $this->refused(fn (): string => $this->created(lines: [['text' => 'Work', 'unit_price' => '100.00'], $line]));
    }

    /**
     * @param \Closure(): string $write
     */
    private function refused(\Closure $write): InvoicingRefused
    {
        try {
            $write();
        } catch (InvoicingRefused $refused) {
            return $refused;
        }
        self::fail('Expected the write to be refused.');
    }

    private function stored(string $id): Invoice
    {
        $this->em->flush();
        $this->em->clear();

        return $this->store->get($id);
    }

    /**
     * @return list<InvoiceLine>
     */
    private function linesOf(Invoice $invoice): array
    {
        return $this->store->lines($invoice);
    }
}
