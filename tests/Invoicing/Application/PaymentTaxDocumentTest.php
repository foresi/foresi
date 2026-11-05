<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Country\CzechRepublic\Invoicing\CzechInvoicing;
use App\Invoicing\Application\InvoicePdf;
use App\Invoicing\Application\InvoiceStatus;
use App\Invoicing\Application\IssuedDocumentFactory;
use App\Invoicing\Application\IssuePaymentTaxDocument;
use App\Invoicing\Application\PaymentState;
use App\Invoicing\Application\RecordPayment;
use App\Invoicing\Application\Savepoint;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\TaxOnReceiptChoice;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Party\Application\TransactionalWork;
use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\VatId;
use App\Tests\Support\PdfTools;

/**
 * A payment received on an advance invoice gets its tax document in the same
 * transaction, from the one path every payment takes.
 */
final class PaymentTaxDocumentTest extends AdvanceTestCase
{
    public function testAFullPaymentOfATwentyOnePercentAdvanceGetsATaxDocument(): void
    {
        $advance = $this->advance([$this->line('80000.00')]);

        $payment = $this->pay($advance, '96800.00', '2026-11-18');

        $document = $this->onlyTaxDocumentOf($advance);
        self::assertSame(DocumentKind::PaymentTaxDocument, $document->kind());
        self::assertSame('D820260001', $document->number());
        self::assertSame([$advance->id(), $payment->id()], [$document->advanceInvoiceId(), $document->paymentId()]);
        self::assertSame(['2026-11-18', '2026-11-18', '2026-11-18'], [$document->issueDate()->format('Y-m-d'), $document->taxPointDate()?->format('Y-m-d'), $document->dueDate()->format('Y-m-d')]);
        self::assertSame(['920260001', '0308', 'PO-1'], [$document->variableSymbol(), $document->constantSymbol(), $document->orderNumber()]);
        self::assertSame([['21.00', 8000000, 1680000, 9680000]], $this->figuresOf($document));
        self::assertSame(9680000, $document->payable()->minor());
        self::assertSame('Example Customer a.s.', $document->customer()->name);
        self::assertSame('Daňový doklad k přijaté platbě zálohové faktury Z920260001, VS 920260001', $this->store->lines($document)[0]->text());
    }

    public function testAnAdvanceAtTheReducedRateIsTaxedAtIt(): void
    {
        $advance = $this->advance([$this->gross('11200.00', VatRateKind::Reduced)]);

        $this->pay($advance, '11200.00');

        self::assertSame([['12.00', 1000000, 120000, 1120000]], $this->figuresOf($this->onlyTaxDocumentOf($advance)));
    }

    public function testAnExemptAdvanceGetsADocumentWithoutVat(): void
    {
        $advance = $this->advance([$this->line('5000.00', treatment: TaxTreatment::Exempt, kind: null)]);

        $this->pay($advance, '5000.00');

        self::assertSame([[null, 500000, 0, 500000]], $this->figuresOf($this->onlyTaxDocumentOf($advance)));
    }

    public function testTwoRatesShareThePaymentInTheProportionOfTheOrder(): void
    {
        // GFR on §20a, example 7: an advance of 20 000 on an order half at each rate.
        $advance = $this->advance([$this->gross('10000.00'), $this->gross('10000.00', VatRateKind::Reduced)]);

        $this->pay($advance, '20000.00');

        self::assertSame([['21.00', 826446, 173554, 1000000], ['12.00', 892857, 107143, 1000000]], $this->figuresOf($this->onlyTaxDocumentOf($advance)));
    }

    public function testAPartOfATwoRateAdvanceSplitsWithoutLosingAUnit(): void
    {
        $advance = $this->advance([$this->gross('100.00'), $this->gross('200.00', VatRateKind::Reduced), $this->gross('50.00')]);

        $this->pay($advance, '100.01');

        $figures = $this->figuresOf($this->onlyTaxDocumentOf($advance));
        self::assertSame([4287, 5714], array_column($figures, 3), 'base rate 150/350 and reduced 200/350 of 100.01, the unit left over to the first');
    }

    public function testTwoPartialPaymentsGetTwoTaxDocuments(): void
    {
        $advance = $this->advance([$this->line('10000.00')]);

        $first = $this->pay($advance, '4840.00', '2026-11-02');
        $second = $this->pay($advance, '7260.00', '2026-11-19');

        $documents = $this->taxDocumentsOf($advance);
        self::assertSame(['D820260001', 'D820260002'], array_map(static fn (\App\Invoicing\Domain\Invoice $document): string => $document->number(), $documents));
        self::assertSame([$first->id(), $second->id()], array_map(static fn (\App\Invoicing\Domain\Invoice $document): ?string => $document->paymentId(), $documents));
        self::assertSame([['21.00', 400000, 84000, 484000]], $this->figuresOf($documents[0]));
        self::assertSame([['21.00', 600000, 126000, 726000]], $this->figuresOf($documents[1]));
        self::assertSame('2026-11-02', $documents[0]->taxPointDate()?->format('Y-m-d'));
        self::assertSame(PaymentState::Paid, PaymentState::of($this->reloaded($advance)->payable(), $this->payments->paidOf($this->reloaded($advance))));
    }

    public function testAnOverpaymentIsTaxedInFull(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);

        $this->pay($advance, '1500.00');

        self::assertSame([['21.00', 123967, 26033, 150000]], $this->figuresOf($this->onlyTaxDocumentOf($advance)));
        self::assertSame(PaymentState::Overpaid, PaymentState::of($this->reloaded($advance)->payable(), $this->payments->paidOf($this->reloaded($advance))));
    }

    public function testOnePaymentOfTwoDocumentsTaxesOnlyTheAdvance(): void
    {
        $invoice = $this->issue->issue($this->input());
        $advance = $this->advance([$this->line('1000.00')]);

        $this->pay($advance, '1210.00', more: [$invoice->id() => '1210.00']);

        self::assertSame([['21.00', 100000, 21000, 121000]], $this->figuresOf($this->onlyTaxDocumentOf($advance)));
        self::assertSame([], $this->taxDocumentsOf($invoice));
    }

    public function testAnInvoiceGetsNoTaxDocument(): void
    {
        $invoice = $this->issue->issue($this->input());

        $this->pay($invoice, '1210.00');

        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM invoice'));
    }

    public function testNoTaxDocumentWhereVatDoesNotArise(): void
    {
        $person = new Party(PartyKind::Person, 'Jana Example', null, null, '');
        $this->em->persist($person);
        $this->em->persist(new PartyAddress($person, AddressType::Billing, new Address('Home Street 1', 'Home City', '100 00', 'CZ')));
        $this->em->flush();
        $consumer = $this->advance([$this->line('1000.00')], ['customerId' => $person->id()]);
        $unknown = $this->advance([$this->line('1000.00')], ['taxOnReceipt' => TaxOnReceiptChoice::Skip]);
        $reverse = $this->advance([$this->line(treatment: TaxTreatment::ReverseCharge, kind: null, supplyCode: '4')]);

        $this->pay($consumer, '1210.00');
        $this->pay($unknown, '1210.00');
        $this->pay($reverse, '1000.00');

        self::assertSame([[], [], []], [$this->taxDocumentsOf($consumer), $this->taxDocumentsOf($unknown), $this->taxDocumentsOf($reverse)]);
        self::assertSame(3, $this->rows('SELECT COUNT(*) FROM payment'));
    }

    public function testAnEuServiceGetsAReverseChargeDocument(): void
    {
        $german = new Party(PartyKind::Company, 'Example Buyer GmbH', null, VatId::fromString('DE123456789'), '');
        $this->em->persist($german);
        $this->em->persist(new PartyAddress($german, AddressType::Billing, new Address('Beispielstrasse 1', 'Berlin', '10115', 'DE')));
        $this->em->flush();
        $advance = $this->advance([$this->line('2000.00', treatment: TaxTreatment::ReverseCharge, kind: null)], ['customerId' => $german->id(), 'language' => DocumentLanguage::English]);

        $this->pay($advance, '2000.00');

        $document = $this->onlyTaxDocumentOf($advance);
        self::assertSame([[null, 200000, 0, 200000]], $this->figuresOf($document));
        self::assertSame(TaxTreatment::ReverseCharge, $this->store->lines($document)[0]->treatment());
        self::assertSame('Tax document on received payment of advance invoice Z920260001, variable symbol 920260001', $this->store->lines($document)[0]->text());
    }

    public function testARefusedDocumentLeavesThePaymentStanding(): void
    {
        // The standard series is archived: the firm has no series to number the document in.
        $series = Series::standard($this->firm, DocumentKind::PaymentTaxDocument);
        $series->archive();
        $this->em->persist($series);
        $this->em->flush();
        $advance = $this->advance([$this->line('1000.00')]);

        $payment = $this->pay($advance, '1210.00');

        self::assertSame([], $this->taxDocumentsOf($advance));
        self::assertSame(121000, $this->payments->paidOf($this->reloaded($advance))->minor());
        self::assertFalse($this->payments->get($payment->id())->isCancelled());
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM series_counter WHERE series_id = ?', [$series->id()]));
    }

    public function testAPaymentWithoutAFirmAccountStillGetsItsDocument(): void
    {
        $advance = $this->advance([$this->line('1000.00')], ['paymentMethod' => PaymentMethod::Cash, 'bankAccount' => null]);
        $this->em->getConnection()->executeStatement('DELETE FROM firm_bank_account');

        $this->pay($advance, '1210.00');

        $document = $this->onlyTaxDocumentOf($advance);
        self::assertNull($document->bankAccount());
        self::assertSame(PaymentMethod::BankTransfer, $document->paymentMethod());
    }

    public function testATaxDocumentIsAlwaysPaidAndNeverOverdue(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $this->pay($advance, '1210.00', '2026-11-01');
        $document = $this->onlyTaxDocumentOf($advance);

        self::assertSame(InvoiceStatus::Paid, InvoiceStatus::ofInvoice($document, new \DateTimeImmutable('2027-01-01'), $this->payments->paidOf($document)));
        self::assertSame(0, $this->payments->paidOf($document)->minor(), 'the money stays allocated to the advance');
    }

    public function testTheTaxDocumentPdfSaysDoNotPayAndHasNoCode(): void
    {
        $advance = $this->advance([$this->line('80000.00')]);
        $this->pay($advance, '96800.00', '2026-11-18');
        $document = self::getContainer()->get(IssuedDocumentFactory::class)->of($this->onlyTaxDocumentOf($advance)->id());

        $text = PdfTools::text(self::getContainer()->get(InvoicePdf::class)->render($document)->bytes);

        self::assertSame('Z920260001', $document->advanceNumber);
        foreach (['Daňový doklad k přijaté platbě', 'D820260001', 'K zálohové faktuře Z920260001', 'Datum přijetí platby (DUZP)', '18. 11. 2026', 'Neplaťte, doklad je již uhrazen', 'Celkem uhrazeno', '80 000,00', '16 800,00', '96 800,00'] as $expected) {
            self::assertStringContainsString($expected, $text);
        }
        self::assertStringNotContainsString('QR Platba', $text);
        self::assertNull(self::getContainer()->get(CzechInvoicing::class)->paymentCode($document));
        self::assertNull(self::getContainer()->get(CzechInvoicing::class)->electronicFile($document));
    }

    public function testATaxDocumentCannotBeEdited(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $this->pay($advance, '1210.00');
        $document = $this->onlyTaxDocumentOf($advance);

        $this->assertRefusedFor('invoice.problem.tax_document_fixed', fn () => $this->edited($document, $this->input(overrides: ['seriesId' => null, 'description' => 'Changed'])));
    }

    public function testATaxDocumentIsNeverIssuedWithoutItsPayment(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('A tax document on payment is issued for a payment only.');

        $this->issue->issue($this->input(overrides: ['seriesId' => null, 'kind' => DocumentKind::PaymentTaxDocument]));
    }

    public function testOnePaymentNeverGetsTwoDocuments(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $payment = $this->pay($advance, '1210.00');
        $taxDocuments = $this->taxDocumentService();

        $again = self::getContainer()->get(TransactionalWork::class)->run(fn (): mixed => $taxDocuments->forPayment($this->payments->get($payment->id()), $advance->id(), $this->czk('1210.00')));

        self::assertNull($again);
        self::assertCount(1, $this->taxDocumentsOf($advance));
    }

    public function testTheSavepointNeedsATransaction(): void
    {
        $this->expectException(\LogicException::class);

        $savepoint = new \ReflectionProperty(IssuePaymentTaxDocument::class, 'savepoint')->getValue($this->taxDocumentService());
        self::assertInstanceOf(Savepoint::class, $savepoint);

        $savepoint->run(static fn (): null => null);
    }

    /**
     * The one the container wires, as phpstan's container inlines it.
     */
    private function taxDocumentService(): IssuePaymentTaxDocument
    {
        $service = new \ReflectionProperty(RecordPayment::class, 'taxDocuments')->getValue($this->record);
        self::assertInstanceOf(IssuePaymentTaxDocument::class, $service);

        return $service;
    }

    /**
     * @param list<mixed> $parameters
     */
    private function rows(string $sql, array $parameters = []): int
    {
        $count = $this->em->getConnection()->fetchOne($sql, $parameters);
        self::assertIsNumeric($count);

        return (int) $count;
    }
}
