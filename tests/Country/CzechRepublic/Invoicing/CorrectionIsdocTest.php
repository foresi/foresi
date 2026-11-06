<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Country\CzechRepublic\Invoicing;

use App\Country\CzechRepublic\Invoicing\CzechInvoicing;
use App\Country\CzechRepublic\Invoicing\IsdocWriter;
use App\Invoicing\Application\IssuedDocument;
use App\Invoicing\Application\IssuedDocumentFactory;
use App\Invoicing\Domain\Invoice;
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
        // The document's own signed differences: ISDOC sets no other convention for a credit note.
        self::assertSame('-400.00', $this->text($path, '/i:Invoice/i:InvoiceLines/i:InvoiceLine[1]/i:LineExtensionAmount'));
        self::assertSame('-484.00', $this->text($path, '/i:Invoice/i:LegalMonetaryTotal/i:PayableAmount'));
    }

    public function testADebitNoteIsTypeThree(): void
    {
        $original = $this->invoice();
        $path = $this->path(new IsdocWriter()->write($this->issued($this->correctionOf($original, '200.00'))));

        self::assertSame('3', $this->text($path, '/i:Invoice/i:DocumentType'));
        $this->assertReferenceTo($original, $path);
        self::assertSame('242.00', $this->text($path, '/i:Invoice/i:LegalMonetaryTotal/i:PayableAmount'));
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
