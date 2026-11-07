<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Country\CzechRepublic\Invoicing\CzechInvoicing;
use App\Invoicing\Application\AdvanceDocuments;
use App\Invoicing\Application\AllocationRequest;
use App\Invoicing\Application\CorrectInvoice;
use App\Invoicing\Application\Deductible;
use App\Invoicing\Application\DeductibleAdvances;
use App\Invoicing\Application\InvoiceFilter;
use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\InvoicePage;
use App\Invoicing\Application\InvoicePdf;
use App\Invoicing\Application\InvoiceRow;
use App\Invoicing\Application\InvoiceStatus;
use App\Invoicing\Application\IssuedDocumentFactory;
use App\Invoicing\Application\IssueRefused;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\PaymentRequest;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\PaymentDirection;
use App\Invoicing\Domain\PaymentSource;
use App\Invoicing\Domain\TaxOnReceiptChoice;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\VatStatus;
use App\Tests\Support\IsdocSchema;
use App\Tests\Support\PdfTools;

/**
 * A final invoice deducts the customer's paid advances, one tax document at a
 * time, with negative lines per rate, and no document twice.
 */
final class AdvanceDeductionTest extends AdvanceTestCase
{
    public function testDeductingATaxDocumentAddsItsNegativeLines(): void
    {
        $document = $this->taxDocumentFor($this->advance([$this->line('1000.00')]), '1210.00');

        $final = $this->issue->issue($this->finalInput([$this->line('3000.00'), ...$this->deductionOf($document)]));

        $stored = $this->reloaded($final);
        self::assertSame([['21.00', 300000, 63000, 363000], ['21.00', -100000, -21000, -121000]], $this->figuresOf($stored));
        self::assertSame(242000, $stored->payable()->minor());
        self::assertTrue($stored->deductsAdvances());
        self::assertSame($final->id(), $this->reloaded($document)->deductedByInvoiceId());
        self::assertSame($document->id(), $this->store->lines($stored)[1]->deducts());
        self::assertSame('Odpočet zálohy VS 920260001 (daňový doklad D820260001)', $this->store->lines($stored)[1]->text());
    }

    public function testACorrectionOfAFinalInvoiceLeavesItsDeductionsOut(): void
    {
        $document = $this->taxDocumentFor($this->advance([$this->line('1000.00')]), '1210.00');
        $final = $this->issue->issue($this->finalInput([$this->line('3000.00'), ...$this->deductionOf($document), $this->line('500.00', text: 'Travel')]));

        $draft = self::getContainer()->get(CorrectInvoice::class)->valuesFrom($this->reloaded($final));

        self::assertSame([['Work', -10000], ['Travel', -10000]], array_map(static fn (LineInput $line): array => [$line->text, $line->quantity->tenThousandths()], $draft->lines));
    }

    public function testATwoRateDocumentDeductsPerRate(): void
    {
        $document = $this->taxDocumentFor($this->advance([$this->gross('10000.00'), $this->gross('10000.00', VatRateKind::Reduced)]), '20000.00');

        $lines = $this->deductionOf($document);

        self::assertCount(2, $lines);
        self::assertSame([[-1000000, VatRateKind::Base], [-1000000, VatRateKind::Reduced]], array_map(static fn (LineInput $line): array => [$line->price->minor(), $line->rateKind], $lines));
    }

    public function testAFullyCoveredInvoiceHasNothingToPay(): void
    {
        $document = $this->taxDocumentFor($this->advance([$this->line('1000.00')]), '1210.00');

        $final = $this->reloaded($this->issue->issue($this->finalInput([$this->line('1000.00'), ...$this->deductionOf($document)])));

        self::assertSame(0, $final->payable()->minor());
        self::assertSame(InvoiceStatus::SettledByAdvances, InvoiceStatus::ofInvoice($final, new \DateTimeImmutable('2027-01-01'), $this->payments->paidOf($final)));
        $issued = self::getContainer()->get(IssuedDocumentFactory::class)->of($final->id());
        self::assertNull(self::getContainer()->get(CzechInvoicing::class)->paymentCode($issued));
        self::assertStringContainsString('Uhrazeno zálohami, nic nehraďte', PdfTools::text(self::getContainer()->get(InvoicePdf::class)->render($issued)->bytes));
    }

    public function testAnOverCoveredInvoiceLeavesADifferenceToRefund(): void
    {
        $document = $this->taxDocumentFor($this->advance([$this->line('2000.00')]), '2420.00');

        $final = $this->reloaded($this->issue->issue($this->finalInput([$this->line('1000.00'), ...$this->deductionOf($document)])));

        self::assertSame(-121000, $final->payable()->minor());
        self::assertSame(-21000, $final->vat()->minor());
        self::assertSame(InvoiceStatus::SettledByAdvances, InvoiceStatus::ofInvoice($final, new \DateTimeImmutable('2027-01-01'), $this->payments->paidOf($final)));
        self::assertStringContainsString('Rozdíl k vrácení', PdfTools::text(self::getContainer()->get(InvoicePdf::class)->render(self::getContainer()->get(IssuedDocumentFactory::class)->of($final->id()))->bytes));
    }

    public function testTheDifferenceToRefundIsRecordedAsARefund(): void
    {
        $document = $this->taxDocumentFor($this->advance([$this->line('2000.00')]), '2420.00');
        $final = $this->reloaded($this->issue->issue($this->finalInput([$this->line('1000.00'), ...$this->deductionOf($document)])));
        self::assertSame(121000, $this->payments->toRefundOf($final)->minor());

        $this->record->record(new PaymentRequest($this->firm->id(), new \DateTimeImmutable('2026-11-20'), $this->czk('1210.00'), PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', [new AllocationRequest($final->id(), $this->czk('1210.00'))], PaymentDirection::Refund));

        $final = $this->reloaded($final);
        self::assertSame(-121000, $this->payments->paidOf($final)->minor());
        self::assertSame(0, $this->payments->toRefundOf($final)->minor());
        self::assertSame(0, $this->payments->remainingOf($final)->minor());
    }

    public function testANegativeTotalWithoutADeductionIsStillRefused(): void
    {
        $this->assertRefusedFor('invoice.problem.payable_negative', fn (): Invoice => $this->issue->issue($this->input([$this->line('-100.00')])));
    }

    public function testADocumentIsDeductedOnce(): void
    {
        $document = $this->taxDocumentFor($this->advance([$this->line('1000.00')]), '1210.00');
        $lines = $this->deductionOf($document);
        $this->issue->issue($this->finalInput([$this->line('3000.00'), ...$lines]));

        self::assertSame([], $this->deductibleIds());
        $this->assertRefusedFor('invoice.problem.deduction_invalid', fn (): Invoice => $this->issue->issue($this->finalInput([$this->line('3000.00'), ...$lines])));
    }

    public function testAClaimAnotherInvoiceHoldsRefusesTheSecond(): void
    {
        // Two invoices saved at once both passed the rules; the claim in the transaction decides.
        $document = $this->taxDocumentFor($this->advance([$this->line('1000.00')]), '1210.00');
        $this->issue->issue($this->finalInput([$this->line('3000.00'), ...$this->deductionOf($document)]));
        $second = $this->issue->issue($this->finalInput([$this->line('3000.00')]));

        try {
            $this->deductions()->claim($this->reloaded($second), [$document->id()]);
            self::fail('Expected the claim to be refused.');
        } catch (IssueRefused $refused) {
            self::assertSame('invoice.problem.already_deducted', $refused->problems[0]->key);
        }
        self::assertFalse(self::getContainer()->get(AdvanceDocuments::class)->claimDeduction($document->id(), $second->id()));
    }

    public function testRemovingTheDeductionOrTrashingTheInvoiceFreesTheDocument(): void
    {
        $document = $this->taxDocumentFor($this->advance([$this->line('1000.00')]), '1210.00');
        $final = $this->issue->issue($this->finalInput([$this->line('3000.00'), ...$this->deductionOf($document)]));

        $this->edited($final, $this->finalInput([$this->line('3000.00')], ['seriesId' => null]));
        self::assertNull($this->reloaded($document)->deductedByInvoiceId());
        self::assertFalse($this->reloaded($final)->deductsAdvances());

        $other = $this->issue->issue($this->finalInput([$this->line('3000.00'), ...$this->deductionOf($document)]));
        $this->trash->trash($other->id(), 'Wrong amount');
        self::assertNull($this->reloaded($document)->deductedByInvoiceId());
        self::assertSame([$document->id()], $this->deductibleIds());

        $this->trash->restore($other->id());
        self::assertSame($other->id(), $this->reloaded($document)->deductedByInvoiceId());
    }

    public function testARestoreIsRefusedWhenAnotherInvoiceDeductsTheDocumentNow(): void
    {
        $document = $this->taxDocumentFor($this->advance([$this->line('1000.00')]), '1210.00');
        $lines = $this->deductionOf($document);
        $first = $this->issue->issue($this->finalInput([$this->line('3000.00'), ...$lines]));
        $this->trash->trash($first->id(), 'Wrong amount');
        $this->issue->issue($this->finalInput([$this->line('3000.00'), ...$lines]));

        try {
            $this->trash->restore($first->id());
            self::fail('Expected the restore to be refused.');
        } catch (IssueRefused $refused) {
            self::assertSame('invoice.problem.already_deducted', $refused->problems[0]->key);
        }
        self::assertTrue($this->reloaded($first)->isTrashed());
    }

    public function testAnEditedInvoiceStillOffersWhatItDeducts(): void
    {
        $document = $this->taxDocumentFor($this->advance([$this->line('1000.00')]), '1210.00');
        $final = $this->issue->issue($this->finalInput([$this->line('3000.00'), ...$this->deductionOf($document)]));

        self::assertSame([$document->id()], $this->deductibleIds($this->reloaded($final)));
        $this->edited($final, $this->finalInput([$this->line('3100.00'), ...$this->deductionOf($document, $this->reloaded($final))], ['seriesId' => null]));
        self::assertSame(254100, $this->reloaded($final)->payable()->minor());
    }

    public function testOnlyAnInvoiceDeducts(): void
    {
        $document = $this->taxDocumentFor($this->advance([$this->line('1000.00')]), '1210.00');

        $this->assertRefusedFor('invoice.problem.deduction_invalid', fn (): Invoice => $this->advance([$this->line('3000.00'), ...$this->deductionOf($document)]));
    }

    public function testACancelledPaymentsDocumentIsNotOffered(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $payment = $this->pay($advance, '1210.00');
        $this->delivered->mark($this->onlyTaxDocumentOf($advance)->id());

        $this->cancel->cancel($payment->id());

        self::assertSame([], $this->deductibleIds());
    }

    public function testAPaidAdvanceOfANonPayerIsDeductedForWhatWasPaid(): void
    {
        $firm = $this->firm(VatStatus::NotRegistered, null, registrationNumber: '12345687');
        $this->em->flush();
        $advance = $this->advance([$this->line('5000.00', treatment: TaxTreatment::NotApplicable, kind: null)], ['firmId' => $firm->id()]);
        $this->pay($advance, '3000.00');

        $offered = self::getContainer()->get(DeductibleAdvances::class)->for($firm, $this->customer->id(), null, DocumentLanguage::Czech);

        self::assertCount(1, $offered);
        self::assertSame([$advance->id(), 'Z920260001'], [$offered[0]->id, $offered[0]->number]);
        self::assertSame([[-300000, TaxTreatment::NotApplicable, $advance->id()]], array_map(static fn (LineInput $line): array => [$line->price->minor(), $line->treatment, $line->deducts], $offered[0]->lines));
        self::assertSame('Odpočet zálohové faktury Z920260001, VS 920260001', $offered[0]->lines[0]->text);
        $final = $this->issue->issue($this->input([$this->line('5000.00', treatment: TaxTreatment::NotApplicable, kind: null), ...$offered[0]->lines], ['firmId' => $firm->id(), 'seriesId' => null]));
        self::assertSame(200000, $this->reloaded($final)->payable()->minor());
        self::assertSame($final->id(), $this->reloaded($advance)->deductedByInvoiceId());
    }

    public function testAnUnpaidAdvanceWithoutVatIsNotOffered(): void
    {
        $firm = $this->firm(VatStatus::NotRegistered, null, registrationNumber: '12345687');
        $this->em->flush();
        $this->advance([$this->line('5000.00', treatment: TaxTreatment::NotApplicable, kind: null)], ['firmId' => $firm->id()]);

        self::assertSame([], self::getContainer()->get(DeductibleAdvances::class)->for($firm, $this->customer->id(), null, DocumentLanguage::Czech));
    }

    public function testTheIsdocOfTheFinalInvoiceCarriesTheTaxedDeposits(): void
    {
        $document = $this->taxDocumentFor($this->advance([$this->line('1000.00')]), '1210.00');
        $final = $this->issue->issue($this->finalInput([$this->line('3000.00'), ...$this->deductionOf($document)]));

        $xml = $this->isdocOf($final);

        IsdocSchema::assertValid($xml);
        self::assertSame(['1'], $this->texts($xml, '//i:InvoiceLines/i:InvoiceLine/i:ID'));
        self::assertSame(['D820260001', '920260001', '1000.00', '1210.00', '21.00'], [
            ...$this->texts($xml, '//i:TaxedDeposits/i:TaxedDeposit/i:ID'),
            ...$this->texts($xml, '//i:TaxedDeposit/i:VariableSymbol'),
            ...$this->texts($xml, '//i:TaxedDeposit/i:TaxableDepositAmount'),
            ...$this->texts($xml, '//i:TaxedDeposit/i:TaxInclusiveDepositAmount'),
            ...$this->texts($xml, '//i:TaxedDeposit/i:ClassifiedTaxCategory/i:Percent'),
        ]);
        $sub = '//i:TaxTotal/i:TaxSubTotal/i:';
        self::assertSame(['3000.00', '630.00', '3630.00', '1000.00', '210.00', '1210.00', '2000.00', '420.00', '2420.00'], array_merge(...array_map(fn (string $name): array => $this->texts($xml, $sub.$name), ['TaxableAmount', 'TaxAmount', 'TaxInclusiveAmount', 'AlreadyClaimedTaxableAmount', 'AlreadyClaimedTaxAmount', 'AlreadyClaimedTaxInclusiveAmount', 'DifferenceTaxableAmount', 'DifferenceTaxAmount', 'DifferenceTaxInclusiveAmount'])));
        $total = '//i:LegalMonetaryTotal/i:';
        self::assertSame(['3000.00', '3630.00', '1000.00', '1210.00', '2000.00', '2420.00', '0.00', '2420.00'], array_merge(...array_map(fn (string $name): array => $this->texts($xml, $total.$name), ['TaxExclusiveAmount', 'TaxInclusiveAmount', 'AlreadyClaimedTaxExclusiveAmount', 'AlreadyClaimedTaxInclusiveAmount', 'DifferenceTaxExclusiveAmount', 'DifferenceTaxInclusiveAmount', 'PaidDepositsAmount', 'PayableAmount'])));
        self::assertSame(['420.00'], $this->texts($xml, '//i:TaxTotal/i:TaxAmount'));
        self::assertSame([], $this->texts($xml, '//i:NonTaxedDeposits/i:NonTaxedDeposit/i:ID'));
    }

    public function testAnAdvanceAtARateTheSupplyLacksIsClaimedInFull(): void
    {
        $document = $this->taxDocumentFor($this->advance([$this->gross('1120.00', VatRateKind::Reduced)]), '1120.00');
        $final = $this->issue->issue($this->finalInput([$this->line('3000.00'), ...$this->deductionOf($document)]));

        $xml = $this->isdocOf($final);

        IsdocSchema::assertValid($xml);
        self::assertSame(['21.00', '12.00'], $this->texts($xml, '//i:TaxTotal/i:TaxSubTotal/i:TaxCategory/i:Percent'));
        self::assertSame(['0.00', '1000.00', '-1000.00'], [$this->texts($xml, '//i:TaxSubTotal/i:TaxableAmount')[1], $this->texts($xml, '//i:TaxSubTotal/i:AlreadyClaimedTaxableAmount')[1], $this->texts($xml, '//i:TaxSubTotal/i:DifferenceTaxableAmount')[1]]);
        self::assertSame(['2510.00'], $this->texts($xml, '//i:LegalMonetaryTotal/i:PayableAmount'));
    }

    public function testTheIsdocOfANonPayerCarriesTheAdvanceAsANonTaxedDeposit(): void
    {
        $firm = $this->firm(VatStatus::NotRegistered, null, registrationNumber: '12345687');
        $this->em->flush();
        $advance = $this->advance([$this->line('5000.00', treatment: TaxTreatment::NotApplicable, kind: null)], ['firmId' => $firm->id()]);
        $this->pay($advance, '3000.00');
        $offered = self::getContainer()->get(DeductibleAdvances::class)->for($firm, $this->customer->id(), null, DocumentLanguage::Czech);
        $final = $this->issue->issue($this->input([$this->line('5000.00', treatment: TaxTreatment::NotApplicable, kind: null), ...$offered[0]->lines], ['firmId' => $firm->id(), 'seriesId' => null]));

        $xml = $this->isdocOf($final);

        IsdocSchema::assertValid($xml);
        self::assertSame(['Z920260001', '920260001', '3000.00'], [...$this->texts($xml, '//i:NonTaxedDeposits/i:NonTaxedDeposit/i:ID'), ...$this->texts($xml, '//i:NonTaxedDeposit/i:VariableSymbol'), ...$this->texts($xml, '//i:NonTaxedDeposit/i:DepositAmount')]);
        self::assertSame(['5000.00', '3000.00', '2000.00'], [...$this->texts($xml, '//i:LegalMonetaryTotal/i:DifferenceTaxInclusiveAmount'), ...$this->texts($xml, '//i:LegalMonetaryTotal/i:PaidDepositsAmount'), ...$this->texts($xml, '//i:LegalMonetaryTotal/i:PayableAmount')]);
        self::assertSame(['1'], $this->texts($xml, '//i:InvoiceLines/i:InvoiceLine/i:ID'));
    }

    public function testTheUnsettledAdvancesAreThePaidOnesNoFinalInvoiceDeductedYet(): void
    {
        $this->advance([$this->line('1000.00')]);
        $taxed = $this->advance([$this->line('1000.00')]);
        $this->taxDocumentFor($taxed, '1210.00');
        $partly = $this->advance([$this->line('2000.00')]);
        $this->pay($partly, '1000.00');
        $deducted = $this->taxDocumentFor($this->advance([$this->line('500.00')]), '605.00');
        $this->issue->issue($this->finalInput([$this->line('3000.00'), ...$this->deductionOf($deducted)]));
        $cancelled = $this->advance([$this->line('700.00')]);
        $this->cancel->cancel($this->pay($cancelled, '847.00')->id());
        // No VAT arose on it, so it waits without a tax document until the advance itself is deducted.
        $skipped = $this->advance([$this->line('300.00')], ['taxOnReceipt' => TaxOnReceiptChoice::Skip]);
        $this->pay($skipped, '363.00');

        $page = $this->listed(DocumentKind::Advance, InvoiceFilter::UNSETTLED);

        self::assertSame([InvoiceFilter::ALL, InvoiceFilter::PAID, InvoiceFilter::UNPAID, InvoiceFilter::OVERDUE, InvoiceFilter::UNSETTLED, InvoiceFilter::TRASH], array_keys($page->tabCounts));
        self::assertSame(3, $page->tabCounts[InvoiceFilter::UNSETTLED]);
        self::assertEqualsCanonicalizing([$taxed->id(), $partly->id(), $skipped->id()], array_map(static fn (InvoiceRow $row): string => $row->id, $page->rows));
        self::assertSame(3, $page->total);
    }

    public function testAnAdvanceDeductedItselfIsSettled(): void
    {
        $firm = $this->firm(VatStatus::NotRegistered, null, registrationNumber: '12345687');
        $this->em->flush();
        $advance = $this->advance([$this->line('5000.00', treatment: TaxTreatment::NotApplicable, kind: null)], ['firmId' => $firm->id()]);
        $this->pay($advance, '3000.00');
        self::assertSame(1, $this->listed(DocumentKind::Advance, InvoiceFilter::UNSETTLED)->tabCounts[InvoiceFilter::UNSETTLED]);
        $offered = self::getContainer()->get(DeductibleAdvances::class)->for($firm, $this->customer->id(), null, DocumentLanguage::Czech);

        $this->issue->issue($this->input([$this->line('5000.00', treatment: TaxTreatment::NotApplicable, kind: null), ...$offered[0]->lines], ['firmId' => $firm->id(), 'seriesId' => null]));

        self::assertSame(0, $this->listed(DocumentKind::Advance, InvoiceFilter::UNSETTLED)->tabCounts[InvoiceFilter::UNSETTLED]);
    }

    public function testTheTaxDocumentsAreSettledOnceAFinalInvoiceDeductsThem(): void
    {
        $open = $this->taxDocumentFor($this->advance([$this->line('1000.00')]), '1210.00');
        $deducted = $this->taxDocumentFor($this->advance([$this->line('500.00')]), '605.00');
        $this->issue->issue($this->finalInput([$this->line('3000.00'), ...$this->deductionOf($deducted)]));
        // Its payment is gone, so no final invoice will deduct it: it waits for a corrective tax document.
        $corrected = $this->advance([$this->line('700.00')]);
        $payment = $this->pay($corrected, '847.00');
        $this->delivered->mark($this->onlyTaxDocumentOf($corrected)->id());
        $this->cancel->cancel($payment->id());
        $trashed = $this->advance([$this->line('800.00')]);
        $this->cancel->cancel($this->pay($trashed, '968.00')->id());

        $settled = $this->listed(DocumentKind::PaymentTaxDocument, InvoiceFilter::SETTLED);
        $unsettled = $this->listed(DocumentKind::PaymentTaxDocument, InvoiceFilter::UNSETTLED);

        self::assertSame([InvoiceFilter::ALL => 3, InvoiceFilter::SETTLED => 1, InvoiceFilter::UNSETTLED => 1, InvoiceFilter::TRASH => 1], $settled->tabCounts);
        self::assertSame([$deducted->id()], array_map(static fn (InvoiceRow $row): string => $row->id, $settled->rows));
        self::assertSame([$open->id()], array_map(static fn (InvoiceRow $row): string => $row->id, $unsettled->rows));
    }

    public function testEachKindListsTheTabsThatMeanSomethingForIt(): void
    {
        self::assertSame([InvoiceFilter::ALL, InvoiceFilter::PAID, InvoiceFilter::UNPAID, InvoiceFilter::OVERDUE, InvoiceFilter::TO_REFUND, InvoiceFilter::TRASH], InvoiceFilter::tabsOf(DocumentKind::Invoice));
        self::assertSame([InvoiceFilter::ALL, InvoiceFilter::PAID, InvoiceFilter::UNPAID, InvoiceFilter::OVERDUE, InvoiceFilter::UNSETTLED, InvoiceFilter::TRASH], InvoiceFilter::tabsOf(DocumentKind::Advance));
        self::assertSame([InvoiceFilter::ALL, InvoiceFilter::SETTLED, InvoiceFilter::UNSETTLED, InvoiceFilter::TRASH], InvoiceFilter::tabsOf(DocumentKind::PaymentTaxDocument));
    }

    public function testATabOfAnotherKindListsTheWholeKind(): void
    {
        $this->issue->issue($this->input());
        $this->issue->issue($this->input());

        $page = $this->listed(DocumentKind::Invoice, InvoiceFilter::UNSETTLED);

        self::assertSame(2, $page->total);
        self::assertArrayNotHasKey(InvoiceFilter::UNSETTLED, $page->tabCounts);
    }

    private function listed(DocumentKind $kind, string $tab): InvoicePage
    {
        $this->em->clear();

        return $this->store->page(new InvoiceFilter(null, $tab, '', InvoiceFilter::DEFAULT_SORT, 1, kind: $kind));
    }

    private function isdocOf(Invoice $invoice): string
    {
        $file = self::getContainer()->get(CzechInvoicing::class)->electronicFile(self::getContainer()->get(IssuedDocumentFactory::class)->of($invoice->id()));
        self::assertNotNull($file);

        return $file->content;
    }

    /**
     * @return list<string>
     */
    private function texts(string $xml, string $path): array
    {
        $document = new \DOMDocument();
        $document->loadXML($xml);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('i', 'http://isdoc.cz/namespace/2013');
        $nodes = $xpath->query($path);
        self::assertNotFalse($nodes);

        $texts = [];
        foreach ($nodes as $node) {
            self::assertInstanceOf(\DOMNode::class, $node);
            $texts[] = $node->textContent;
        }

        return $texts;
    }

    private function taxDocumentFor(Invoice $advance, string $amount): Invoice
    {
        $this->pay($advance, $amount);

        return $this->onlyTaxDocumentOf($advance);
    }

    /**
     * @return list<LineInput>
     */
    private function deductionOf(Invoice $document, ?Invoice $except = null): array
    {
        foreach (self::getContainer()->get(DeductibleAdvances::class)->for($this->firm, $this->customer->id(), $except, DocumentLanguage::Czech) as $deductible) {
            if ($deductible->id === $document->id()) {
                return $deductible->lines;
            }
        }
        self::fail('The document is not offered.');
    }

    /**
     * @return list<string>
     */
    private function deductibleIds(?Invoice $except = null): array
    {
        $this->em->clear();

        return array_map(static fn (Deductible $deductible): string => $deductible->id, self::getContainer()->get(DeductibleAdvances::class)->for($this->firm, $this->customer->id(), $except, DocumentLanguage::Czech));
    }

    /**
     * @param list<LineInput>      $lines
     * @param array<string, mixed> $overrides
     */
    private function finalInput(array $lines, array $overrides = []): InvoiceInput
    {
        return $this->input($lines, [...['kind' => DocumentKind::Invoice], ...$overrides]);
    }
}
