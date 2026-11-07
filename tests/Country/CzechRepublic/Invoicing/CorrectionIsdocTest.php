<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Country\CzechRepublic\Invoicing;

use App\Country\CzechRepublic\Invoicing\CzechInvoicing;
use App\Country\CzechRepublic\Invoicing\IsdocWriter;
use App\Invoicing\Application\CorrectionInput;
use App\Invoicing\Application\IssuedDocument;
use App\Invoicing\Application\IssuedDocumentFactory;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Domain\CorrectionType;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tests\Invoicing\Application\AdvanceTestCase;
use App\Tests\Support\IsdocSchema;

/**
 * A corrective tax document is ISDOC type 2 (credit note) or 3 (debit note) of an
 * invoice, or 6 of a tax document on payment, and names the document it corrects.
 */
final class CorrectionIsdocTest extends AdvanceTestCase
{
    private const string NS = 'http://isdoc.cz/namespace/2013';

    public function testACreditNoteIsTypeTwoAndNamesItsInvoice(): void
    {
        $original = $this->invoice();
        $credit = $this->issued($this->correctionOf($original, '-400.00'));
        $path = $this->path(new IsdocWriter()->write($credit));

        self::assertSame('2', $this->text($path, '/i:Invoice/i:DocumentType'));
        $this->assertReferenceTo($original, $path);
        self::assertSame('2026-11-20', $this->text($path, '/i:Invoice/i:TaxPointDate'));
        // Positive amounts, the type says they subtract (ISDOC 6.0.2, A.6).
        self::assertSame('400.00', $this->text($path, '/i:Invoice/i:InvoiceLines/i:InvoiceLine[1]/i:LineExtensionAmount'));
        self::assertSame('484.00', $this->text($path, '/i:Invoice/i:LegalMonetaryTotal/i:PayableAmount'));
    }

    public function testAFullCreditNoteIsWrittenInPositiveAmounts(): void
    {
        $credit = $this->issued($this->correctionOf($this->invoice(), '-1000.00'));
        $xml = new IsdocWriter()->write($credit);
        $path = $this->path($xml);

        self::assertSame('2', $this->text($path, '/i:Invoice/i:DocumentType'));
        $line = '/i:Invoice/i:InvoiceLines/i:InvoiceLine[1]/';
        self::assertSame(['1.0000', '1000.00', '1210.00', '210.00', '1000.00', '1210.00'], [
            $this->text($path, $line.'i:InvoicedQuantity'),
            $this->text($path, $line.'i:LineExtensionAmount'),
            $this->text($path, $line.'i:LineExtensionAmountTaxInclusive'),
            $this->text($path, $line.'i:LineExtensionTaxAmount'),
            $this->text($path, $line.'i:UnitPrice'),
            $this->text($path, $line.'i:UnitPriceTaxInclusive'),
        ]);
        $this->assertTotals($path, '1000.00', '210.00', '1210.00');
        self::assertSame('1210.00', $this->text($path, '/i:Invoice/i:PaymentMeans/i:Payment/i:PaidAmount'));
        self::assertStringNotContainsString('>-', $xml);
        IsdocSchema::assertValid($xml);
    }

    public function testAPartialCreditNoteAtTwoRatesIsPositivePerRate(): void
    {
        $original = $this->issue->issue($this->input([$this->line('1000.00'), $this->line('500.00', kind: VatRateKind::Reduced)], ['variableSymbol' => '']));
        $credit = $this->issued($this->correctionWith($original, [$this->credit('400.00'), $this->credit('100.00', VatRateKind::Reduced)]));
        $xml = new IsdocWriter()->write($credit);
        $path = $this->path($xml);

        self::assertSame('2', $this->text($path, '/i:Invoice/i:DocumentType'));
        self::assertSame('400.00', $this->text($path, '/i:Invoice/i:InvoiceLines/i:InvoiceLine[1]/i:LineExtensionAmount'));
        self::assertSame('112.00', $this->text($path, '/i:Invoice/i:InvoiceLines/i:InvoiceLine[2]/i:LineExtensionAmountTaxInclusive'));
        $rate = static fn (string $percent): string => '/i:Invoice/i:TaxTotal/i:TaxSubTotal[i:TaxCategory/i:Percent="'.$percent.'"]/';
        self::assertSame(['400.00', '84.00', '484.00', '400.00'], [
            $this->text($path, $rate('21.00').'i:TaxableAmount'),
            $this->text($path, $rate('21.00').'i:TaxAmount'),
            $this->text($path, $rate('21.00').'i:TaxInclusiveAmount'),
            $this->text($path, $rate('21.00').'i:DifferenceTaxableAmount'),
        ]);
        self::assertSame(['100.00', '12.00', '112.00', '112.00'], [
            $this->text($path, $rate('12.00').'i:TaxableAmount'),
            $this->text($path, $rate('12.00').'i:TaxAmount'),
            $this->text($path, $rate('12.00').'i:TaxInclusiveAmount'),
            $this->text($path, $rate('12.00').'i:DifferenceTaxInclusiveAmount'),
        ]);
        $this->assertTotals($path, '500.00', '96.00', '596.00');
        self::assertStringNotContainsString('>-', $xml);
        IsdocSchema::assertValid($xml);
    }

    public function testTheRoundingOfACreditNoteIsPositiveToo(): void
    {
        $original = $this->issue->issue($this->input([$this->line('2000.00')], ['variableSymbol' => '']));
        $path = $this->path(new IsdocWriter()->write($this->issued($this->correctionOf($original, '-1260.00'))));

        // DifferenceTaxInclusiveAmount + PayableRoundingAmount = PayableAmount, all positive.
        self::assertSame(['1524.60', '0.40', '1525.00'], [
            $this->text($path, '/i:Invoice/i:LegalMonetaryTotal/i:DifferenceTaxInclusiveAmount'),
            $this->text($path, '/i:Invoice/i:LegalMonetaryTotal/i:PayableRoundingAmount'),
            $this->text($path, '/i:Invoice/i:LegalMonetaryTotal/i:PayableAmount'),
        ]);
    }

    public function testAMixedCreditNoteWritesItsMinorityLineNegative(): void
    {
        $credit = $this->issued($this->correctionWith($this->invoice(), [$this->credit('1000.00'), $this->line('200.00')]));
        $xml = new IsdocWriter()->write($credit);
        $path = $this->path($xml);

        self::assertSame('2', $this->text($path, '/i:Invoice/i:DocumentType'));
        $first = '/i:Invoice/i:InvoiceLines/i:InvoiceLine[1]/';
        $second = '/i:Invoice/i:InvoiceLines/i:InvoiceLine[2]/';
        self::assertSame(['1.0000', '1000.00', '1000.00'], [$this->text($path, $first.'i:InvoicedQuantity'), $this->text($path, $first.'i:UnitPrice'), $this->text($path, $first.'i:LineExtensionAmount')]);
        // The quantity stays positive, the unit price carries the sign: quantity x unit price = amount.
        self::assertSame(['1.0000', '-200.00', '-200.00', '-242.00', '-242.00'], [
            $this->text($path, $second.'i:InvoicedQuantity'),
            $this->text($path, $second.'i:UnitPrice'),
            $this->text($path, $second.'i:LineExtensionAmount'),
            $this->text($path, $second.'i:LineExtensionAmountTaxInclusive'),
            $this->text($path, $second.'i:UnitPriceTaxInclusive'),
        ]);
        $this->assertTotals($path, '800.00', '168.00', '968.00');
        IsdocSchema::assertValid($xml);
    }

    public function testAMixedDebitNoteWritesItsMinorityLineNegativeToo(): void
    {
        $debit = $this->issued($this->correctionWith($this->invoice(), [$this->line('500.00'), $this->credit('100.00')]));
        $path = $this->path(new IsdocWriter()->write($debit));

        self::assertSame('3', $this->text($path, '/i:Invoice/i:DocumentType'));
        $second = '/i:Invoice/i:InvoiceLines/i:InvoiceLine[2]/';
        self::assertSame(['1.0000', '-100.00', '-100.00'], [$this->text($path, $second.'i:InvoicedQuantity'), $this->text($path, $second.'i:UnitPrice'), $this->text($path, $second.'i:LineExtensionAmount')]);
        $this->assertTotals($path, '400.00', '84.00', '484.00');
    }

    public function testADebitNoteIsTypeThree(): void
    {
        $original = $this->invoice();
        $path = $this->path(new IsdocWriter()->write($this->issued($this->correctionOf($original, '200.00'))));

        self::assertSame('3', $this->text($path, '/i:Invoice/i:DocumentType'));
        $this->assertReferenceTo($original, $path);
        self::assertSame('1.0000', $this->text($path, '/i:Invoice/i:InvoiceLines/i:InvoiceLine[1]/i:InvoicedQuantity'));
        self::assertSame('200.00', $this->text($path, '/i:Invoice/i:InvoiceLines/i:InvoiceLine[1]/i:UnitPrice'));
        self::assertSame('200.00', $this->text($path, '/i:Invoice/i:InvoiceLines/i:InvoiceLine[1]/i:LineExtensionAmount'));
        $this->assertTotals($path, '200.00', '42.00', '242.00');
    }

    public function testACorrectionOfATaxDocumentOnPaymentIsTypeSixWithItsTaxPoint(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $payment = $this->pay($advance, '1210.00', '2026-11-10');
        $taxDocument = $this->onlyTaxDocumentOf($advance);
        $this->delivered->mark($taxDocument->id());
        $this->em->clear();
        $this->cancel->cancel($payment->id());
        $this->em->clear();
        $corrections = $this->store->correctionsOf($this->store->get($taxDocument->id()));
        self::assertCount(1, $corrections);

        $path = $this->path(new IsdocWriter()->write($this->issued($corrections[0])));

        self::assertSame('6', $this->text($path, '/i:Invoice/i:DocumentType'));
        $this->assertReferenceTo($this->store->get($taxDocument->id()), $path);
        self::assertSame('2026-11-20', $this->text($path, '/i:Invoice/i:TaxPointDate'));
        // Always in positive amounts, as a credit note (ISDOC 6.0.2, A.6).
        self::assertSame('1.0000', $this->text($path, '/i:Invoice/i:InvoiceLines/i:InvoiceLine[1]/i:InvoicedQuantity'));
        self::assertSame('1000.00', $this->text($path, '/i:Invoice/i:InvoiceLines/i:InvoiceLine[1]/i:LineExtensionAmount'));
        $this->assertTotals($path, '1000.00', '210.00', '1210.00');
    }

    public function testAnInvoiceKeepsTheSignsOfItsLinesAsStored(): void
    {
        $path = $this->path(new IsdocWriter()->write($this->issued($this->issue->issue($this->input([$this->line('1000.00'), $this->credit('100.00')], ['variableSymbol' => ''])))));

        self::assertSame('1', $this->text($path, '/i:Invoice/i:DocumentType'));
        $second = '/i:Invoice/i:InvoiceLines/i:InvoiceLine[2]/';
        self::assertSame(['-1.0000', '100.00', '121.00', '-100.00'], [
            $this->text($path, $second.'i:InvoicedQuantity'),
            $this->text($path, $second.'i:UnitPrice'),
            $this->text($path, $second.'i:UnitPriceTaxInclusive'),
            $this->text($path, $second.'i:LineExtensionAmount'),
        ]);
        $this->assertTotals($path, '900.00', '189.00', '1089.00');
    }

    public function testAnInvoiceNamesNoOriginal(): void
    {
        $path = $this->path(new IsdocWriter()->write($this->issued($this->invoice())));

        self::assertSame('1', $this->text($path, '/i:Invoice/i:DocumentType'));
        self::assertSame(0, $this->nodes($path, '/i:Invoice/i:OriginalDocumentReferences'));
    }

    public function testAllThreeTypesValidateAgainstTheSchema(): void
    {
        $original = $this->invoice();
        IsdocSchema::assertValid(new IsdocWriter()->write($this->issued($this->correctionOf($original, '-400.00'))));
        IsdocSchema::assertValid(new IsdocWriter()->write($this->issued($this->correctionOf($original, '200.00'))));

        $advance = $this->advance([$this->line('1000.00')]);
        $payment = $this->pay($advance, '1210.00', '2026-11-10');
        $taxDocument = $this->onlyTaxDocumentOf($advance);
        $this->delivered->mark($taxDocument->id());
        $this->em->clear();
        $this->cancel->cancel($payment->id());
        $this->em->clear();
        IsdocSchema::assertValid(new IsdocWriter()->write($this->issued($this->store->correctionsOf($this->store->get($taxDocument->id()))[0])));
    }

    public function testTheCountryWritesACorrectionAsTheElectronicFile(): void
    {
        $credit = $this->issued($this->correctionOf($this->invoice(), '-400.00'));

        $file = self::getContainer()->get(CzechInvoicing::class)->electronicFile($credit);

        self::assertNotNull($file);
        self::assertSame('invoice.isdoc', $file->name);
        self::assertSame('2', $this->text($this->path($file->content), '/i:Invoice/i:DocumentType'));
    }

    public function testOnlyADebitNoteAsksForPayment(): void
    {
        $original = $this->invoice();

        self::assertTrue($this->issued($this->correctionOf($original, '200.00'))->asksForPayment());
        self::assertFalse($this->issued($this->correctionOf($original, '0.00'))->asksForPayment());
    }

    public function testACorrectionWithoutTheDocumentItCorrectsIsNoFile(): void
    {
        $credit = $this->issued($this->correctionOf($this->invoice(), '-400.00'));
        $reflection = new \ReflectionClass($credit);
        $unnamed = $reflection->newInstanceWithoutConstructor();
        foreach ($reflection->getProperties() as $property) {
            $property->setValue($unnamed, 'corrected' === $property->getName() ? null : $property->getValue($credit));
        }

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('A corrective tax document names the document it corrects.');
        new IsdocWriter()->write($unnamed);
    }

    private function assertTotals(\DOMXPath $path, string $base, string $vat, string $gross): void
    {
        self::assertSame([$base, $vat, $gross, $base, $gross, $gross], [
            $this->text($path, '/i:Invoice/i:LegalMonetaryTotal/i:TaxExclusiveAmount'),
            $this->text($path, '/i:Invoice/i:TaxTotal/i:TaxAmount'),
            $this->text($path, '/i:Invoice/i:LegalMonetaryTotal/i:TaxInclusiveAmount'),
            $this->text($path, '/i:Invoice/i:LegalMonetaryTotal/i:DifferenceTaxExclusiveAmount'),
            $this->text($path, '/i:Invoice/i:LegalMonetaryTotal/i:DifferenceTaxInclusiveAmount'),
            $this->text($path, '/i:Invoice/i:LegalMonetaryTotal/i:PayableAmount'),
        ]);
    }

    /**
     * @param list<LineInput> $lines
     */
    private function correctionWith(Invoice $original, array $lines): Invoice
    {
        return $this->issue->issue($this->input($lines, [
            'seriesId' => null,
            'kind' => DocumentKind::CorrectionDocument,
            'issueDate' => new \DateTimeImmutable('2026-11-20'),
            'taxPointDate' => new \DateTimeImmutable('2026-11-20'),
            'variableSymbol' => '',
            'correction' => new CorrectionInput($original->id(), 'Returned goods', CorrectionType::Change, new \DateTimeImmutable('2026-11-20')),
        ]));
    }

    private function credit(string $price, VatRateKind $kind = VatRateKind::Base): LineInput
    {
        return new LineInput('Work', Quantity::fromString('-1'), 'h', Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, $kind, null, Percent::fromString('0'));
    }

    private function invoice(): Invoice
    {
        return $this->issue->issue($this->input([$this->line('1000.00')], ['variableSymbol' => '']));
    }

    private function issued(Invoice $invoice): IssuedDocument
    {
        return self::getContainer()->get(IssuedDocumentFactory::class)->of($invoice->id());
    }

    private function assertReferenceTo(Invoice $original, \DOMXPath $path): void
    {
        $reference = '/i:Invoice/i:OriginalDocumentReferences/i:OriginalDocumentReference';
        self::assertSame(1, $this->nodes($path, $reference));
        self::assertSame($original->number(), $this->text($path, $reference.'/i:ID'));
        self::assertSame($original->issueDate()->format('Y-m-d'), $this->text($path, $reference.'/i:IssueDate'));
        self::assertSame($original->id(), $this->text($path, $reference.'/i:UUID'));
    }

    private function path(string $xml): \DOMXPath
    {
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($xml));
        $path = new \DOMXPath($document);
        $path->registerNamespace('i', self::NS);

        return $path;
    }

    private function text(\DOMXPath $path, string $query): string
    {
        $value = $path->evaluate('string('.$query.')');
        \assert(\is_string($value));

        return $value;
    }

    private function nodes(\DOMXPath $path, string $query): int
    {
        $nodes = $path->query($query);
        self::assertInstanceOf(\DOMNodeList::class, $nodes);

        return $nodes->count();
    }
}
