<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Country\CzechRepublic\Invoicing\CzechInvoicing;
use App\Invoicing\Application\AdvanceDocuments;
use App\Invoicing\Application\Deductible;
use App\Invoicing\Application\DeductibleAdvances;
use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\InvoicePdf;
use App\Invoicing\Application\InvoiceStatus;
use App\Invoicing\Application\IssuedDocumentFactory;
use App\Invoicing\Application\IssueRefused;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\VatStatus;
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

        $this->expectException(\DomainException::class);

        $this->trash->restore($first->id());
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
