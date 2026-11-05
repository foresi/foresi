<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\AdvanceDocuments;
use App\Invoicing\Application\AllocationRequest;
use App\Invoicing\Application\CorrectionInput;
use App\Invoicing\Application\CorrectionRules;
use App\Invoicing\Application\PaymentRequest;
use App\Invoicing\Application\UncorrectedTaxDocument;
use App\Invoicing\Application\UncorrectedTaxDocuments;
use App\Invoicing\Domain\CorrectionType;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\Payment;
use App\Invoicing\Domain\PaymentDirection;
use App\Invoicing\Domain\PaymentSource;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\VatRateKind;
use App\Party\Domain\PaymentMethod;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

/**
 * A delivered tax document on payment is corrected when its payment is cancelled or
 * refunded: at the rates of the receipt, dated on the day of the fact, never above
 * the document, and only while no final invoice deducts it.
 */
final class TaxDocumentCorrectionTest extends AdvanceTestCase
{
    public function testCancellingThePaymentCorrectsTheDeliveredTaxDocument(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $payment = $this->pay($advance, '1210.00', '2026-11-10');
        $document = $this->deliveredTaxDocumentOf($advance);
        Clock::set(new MockClock('2026-11-25 09:00:00', 'UTC'));

        $this->cancel->cancel($payment->id());

        $correction = $this->onlyCorrectionOf($document);
        self::assertSame('Zrušení platby zálohy', $correction->correctionReason());
        self::assertSame(CorrectionType::Change, $correction->correctionType());
        self::assertSame('2026-11-25', $correction->decisiveDate()?->format('Y-m-d'));
        self::assertSame('2026-11-25', $correction->taxPointDate()?->format('Y-m-d'));
        self::assertSame([['21.00', -100000, -21000, -121000]], $this->figuresOf($correction));
        self::assertSame([], $this->waiting());
    }

    public function testARefundCorrectsTheDeliveredTaxDocument(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $this->pay($advance, '1210.00', '2026-11-10');
        $document = $this->deliveredTaxDocumentOf($advance);

        $this->refund($advance, '1210.00', '2026-11-18');

        $correction = $this->onlyCorrectionOf($document);
        self::assertSame('Vrácení platby zálohy', $correction->correctionReason());
        self::assertSame('2026-11-18', $correction->decisiveDate()?->format('Y-m-d'));
        self::assertSame([['21.00', -100000, -21000, -121000]], $this->figuresOf($correction));
        self::assertSame([], $this->waiting());
    }

    public function testAPartRefundCorrectsThePartRefunded(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $this->pay($advance, '1210.00', '2026-11-10');
        $document = $this->deliveredTaxDocumentOf($advance);

        $this->refund($advance, '605.00', '2026-11-18');

        self::assertSame([['21.00', -50000, -10500, -60500]], $this->figuresOf($this->onlyCorrectionOf($document)));
    }

    public function testARefundBeforeDeliveryTrashesTheTaxDocument(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $this->pay($advance, '1210.00', '2026-11-10');

        $this->refund($advance, '1210.00', '2026-11-18');

        $document = $this->onlyTaxDocumentOf($advance);
        self::assertTrue($document->isTrashed());
        self::assertSame([], $this->store->correctionsOf($document));
        self::assertSame([], $this->waiting());
    }

    public function testAPartRefundBeforeDeliveryReissuesTheTaxDocumentForWhatStaysPaid(): void
    {
        $advance = $this->advance([$this->line('10000.00')]);
        $this->pay($advance, '12100.00', '2026-11-10');

        $this->refund($advance, '2100.00', '2026-11-18');

        $live = array_values(array_filter($this->taxDocumentsOf($advance), static fn (Invoice $document): bool => !$document->isTrashed()));
        self::assertCount(1, $live);
        self::assertSame(1000000, $live[0]->gross()->minor());
        self::assertSame('2026-11-10', $live[0]->taxPointDate()?->format('Y-m-d'), 'taxed on the receipt as before');
        self::assertCount(2, $this->taxDocumentsOf($advance), 'the first stays in the trash');
        self::assertSame([], $this->waiting());
    }

    public function testAReplacedTaxDocumentStaysInTheTrash(): void
    {
        $advance = $this->advance([$this->line('10000.00')]);
        $this->pay($advance, '12100.00', '2026-11-10');
        $this->refund($advance, '2100.00', '2026-11-18');
        $trashed = array_values(array_filter($this->taxDocumentsOf($advance), static fn (Invoice $document): bool => $document->isTrashed()));

        $this->expectException(\App\Invoicing\Application\PaymentRefused::class);
        $this->trash->restore($trashed[0]->id());
    }

    public function testADeductedTaxDocumentWaitsForItsCorrection(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $payment = $this->pay($advance, '1210.00', '2026-11-10');
        $document = $this->deliveredTaxDocumentOf($advance);
        $final = $this->issue->issue($this->input());
        self::assertTrue(self::getContainer()->get(AdvanceDocuments::class)->claimDeduction($document->id(), $final->id()));

        $this->cancel->cancel($payment->id());

        self::assertSame([], $this->store->correctionsOf($this->reloaded($document)));
        $waiting = $this->waiting();
        self::assertCount(1, $waiting);
        self::assertSame($document->id(), $waiting[0]->documentId);
        self::assertTrue($waiting[0]->deducted);
        self::assertSame(121000, $waiting[0]->amount->minor());
        self::assertSame('2026-11-20', $waiting[0]->decisiveDate->format('Y-m-d'));
        self::assertSame('2026-12-05', $waiting[0]->deadline->format('Y-m-d'));
        self::assertSame(15, $waiting[0]->daysLeft);
    }

    public function testADeductedTaxDocumentRefusesACorrection(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $document = $this->taxDocumentPaidOn($advance, '1210.00');
        self::assertTrue(self::getContainer()->get(AdvanceDocuments::class)->claimDeduction($document->id(), $this->issue->issue($this->input())->id()));

        $this->assertRefusedFor('invoice.problem.correction_deducted', fn (): Invoice => $this->issue->issue($this->correctionInput($document, '-1210.00')));
    }

    public function testATaxDocumentOnPaymentIsCorrectedAsAChangeOnly(): void
    {
        $document = $this->taxDocumentPaidOn($this->advance([$this->line('1000.00')]), '1210.00');
        $input = $this->correctionInput($document, '-1210.00');
        $input = $this->input($input->lines, ['seriesId' => null, 'kind' => DocumentKind::CorrectionDocument, 'variableSymbol' => '', 'taxPointDate' => new \DateTimeImmutable('2026-11-10'), 'correction' => new CorrectionInput($document->id(), 'Wrong amount', CorrectionType::Error, new \DateTimeImmutable('2026-11-20'))]);

        $this->assertRefusedFor('invoice.problem.correction_payment_change', fn (): Invoice => $this->issue->issue($input));
    }

    public function testATaxDocumentOnTwoRatesIsCorrectedPerRate(): void
    {
        $advance = $this->advance([$this->gross('1210.00'), $this->gross('560.00', VatRateKind::Reduced)]);
        $payment = $this->pay($advance, '1770.00', '2026-11-10');
        $document = $this->deliveredTaxDocumentOf($advance);

        $this->cancel->cancel($payment->id());

        self::assertSame([['21.00', -100000, -21000, -121000], ['12.00', -50000, -6000, -56000]], $this->figuresOf($this->onlyCorrectionOf($document)));
    }

    public function testTheRatesAreThoseOfTheReceiptDay(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $payment = $this->pay($advance, '1210.00', '2026-11-10');
        $document = $this->deliveredTaxDocumentOf($advance);
        Clock::set(new MockClock('2027-01-05 09:00:00', 'UTC'));

        $this->cancel->cancel($payment->id());

        $correction = $this->onlyCorrectionOf($document);
        self::assertSame('2027-01-05', $correction->taxPointDate()?->format('Y-m-d'), 'taxed on the day of the fact');
        self::assertSame('2026-11-10', self::getContainer()->get(CorrectionRules::class)->rateDate($this->correctionInput($document, '-1210.00'))?->format('Y-m-d'), 'at the rates of the receipt');
        self::assertSame('1', $correction->exchangeRate());
    }

    public function testACorrectionNeverGoesBeyondTheTaxDocument(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $payment = $this->pay($advance, '1210.00', '2026-11-10');
        $document = $this->deliveredTaxDocumentOf($advance);
        $this->cancel->cancel($payment->id());

        $this->assertRefusedFor('invoice.problem.correction_over_original', fn (): Invoice => $this->issue->issue($this->correctionInput($document, '-1.00')));
    }

    private function deliveredTaxDocumentOf(Invoice $advance): Invoice
    {
        $document = $this->onlyTaxDocumentOf($advance);
        $this->delivered->mark($document->id());
        $this->em->clear();

        return $document;
    }

    private function taxDocumentPaidOn(Invoice $advance, string $amount): Invoice
    {
        $this->pay($advance, $amount, '2026-11-10');

        return $this->deliveredTaxDocumentOf($advance);
    }

    private function refund(Invoice $advance, string $amount, string $on): Payment
    {
        $payment = $this->record->record(new PaymentRequest($advance->firm()->id(), new \DateTimeImmutable($on), $this->czk($amount), PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', [new AllocationRequest($advance->id(), $this->czk($amount))], PaymentDirection::Refund));
        $this->em->clear();

        return $payment;
    }

    private function onlyCorrectionOf(Invoice $document): Invoice
    {
        $this->em->clear();
        $corrections = $this->store->correctionsOf($this->store->get($document->id()));
        self::assertCount(1, $corrections);
        self::assertSame(DocumentKind::CorrectionDocument, $corrections[0]->kind());
        self::assertSame($document->id(), $corrections[0]->correctedInvoiceId());

        return $corrections[0];
    }

    /**
     * @return list<UncorrectedTaxDocument>
     */
    private function waiting(): array
    {
        $this->em->clear();

        return self::getContainer()->get(UncorrectedTaxDocuments::class)->on(new \DateTimeImmutable('2026-11-20'));
    }

    private function correctionInput(Invoice $document, string $gross): \App\Invoicing\Application\InvoiceInput
    {
        $line = $this->line(ltrim($gross, '-'), PriceType::WithVat);
        $line = new \App\Invoicing\Application\LineInput($line->text, Quantity::fromString(str_starts_with($gross, '-') ? '-1' : '1'), $line->unit, $line->price, $line->priceType, $line->treatment, $line->rateKind, $line->supplyCode, $line->discount);

        return $this->input([$line], [
            'seriesId' => null,
            'kind' => DocumentKind::CorrectionDocument,
            'issueDate' => new \DateTimeImmutable('2026-11-20'),
            'taxPointDate' => new \DateTimeImmutable('2026-11-20'),
            'variableSymbol' => '',
            'correction' => new CorrectionInput($document->id(), 'Advance refunded', CorrectionType::Change, new \DateTimeImmutable('2026-11-20')),
        ]);
    }
}
