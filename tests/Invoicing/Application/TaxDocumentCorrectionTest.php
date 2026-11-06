<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\AdvanceDocuments;
use App\Invoicing\Application\AllocationRequest;
use App\Invoicing\Application\CorrectInvoice;
use App\Invoicing\Application\CorrectionInput;
use App\Invoicing\Application\CorrectionRules;
use App\Invoicing\Application\CorrectPaymentTaxDocument;
use App\Invoicing\Application\LineInput;
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
use App\Tests\Party\Support\FirmAccounts;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

/**
 * A delivered tax document on payment is corrected when its payment is cancelled or
 * refunded: at the rates of the receipt, dated on the day of the fact, never above
 * the document, and only while no final invoice deducts it.
 */
final class TaxDocumentCorrectionTest extends AdvanceTestCase
{
    private const string SECOND_IBAN = 'CZ5508000000001234567899';

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

    public function testARefundOverTwoPaymentsBeforeDeliveryTrashesTheLatestAndReissuesTheEarlier(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $this->pay($advance, '605.00', '2026-11-10');
        $this->pay($advance, '605.00', '2026-11-12');

        $this->refund($advance, '1000.00', '2026-11-18');

        $live = array_values(array_filter($this->taxDocumentsOf($advance), static fn (Invoice $document): bool => !$document->isTrashed()));
        self::assertCount(1, $live);
        self::assertSame(21000, $live[0]->gross()->minor());
        self::assertSame('2026-11-10', $live[0]->taxPointDate()?->format('Y-m-d'), 'taxed on the earlier receipt');
        self::assertCount(3, $this->taxDocumentsOf($advance), 'both first ones stay in the trash');
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
        self::assertFalse($waiting[0]->isLate());
        $onTheDeadline = self::getContainer()->get(UncorrectedTaxDocuments::class)->on(new \DateTimeImmutable('2026-12-05'));
        self::assertSame([0, false], [$onTheDeadline[0]->daysLeft, $onTheDeadline[0]->isLate()]);
        $dayAfter = self::getContainer()->get(UncorrectedTaxDocuments::class)->on(new \DateTimeImmutable('2026-12-06'));
        self::assertSame([-1, true], [$dayAfter[0]->daysLeft, $dayAfter[0]->isLate()]);
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

    public function testOnlyADeliveredTaxDocumentOnPaymentOutOfTheTrashIsCorrected(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->delivered->mark($invoice->id());
        $advance = $this->advance([$this->line('1000.00')]);
        $this->pay($advance, '1210.00', '2026-11-10');

        self::assertFalse(CorrectPaymentTaxDocument::corrects($this->reloaded($invoice)), 'not a tax document on payment');
        self::assertFalse(CorrectPaymentTaxDocument::corrects($this->onlyTaxDocumentOf($advance)), 'not delivered');
        self::assertTrue(CorrectPaymentTaxDocument::corrects($this->reloaded($this->deliveredTaxDocumentOf($advance))));
    }

    public function testATrashedTaxDocumentOfACancelledPaymentHasNothingDue(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $payment = $this->pay($advance, '1210.00', '2026-11-10');

        $this->cancel->cancel($payment->id());

        $document = $this->onlyTaxDocumentOf($advance);
        self::assertTrue($document->isTrashed());
        self::assertNull($this->corrections()->due($document));
    }

    public function testARefundGoesBackOnTheLatestPaymentFirst(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $this->pay($advance, '605.00', '2026-11-10');
        $this->pay($advance, '605.00', '2026-11-12');
        [$first, $second] = $this->deliveredTaxDocumentsOf($advance);

        $this->refund($advance, '605.00', '2026-11-18');

        self::assertSame([], $this->store->correctionsOf($this->reloaded($first)));
        self::assertNull($this->corrections()->due($this->reloaded($first)));
        self::assertSame(-60500, $this->onlyCorrectionOf($second)->gross()->minor());
    }

    public function testARefundOverTwoPaymentsCorrectsTheLatestFirst(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $this->pay($advance, '605.00', '2026-11-10');
        $this->pay($advance, '605.00', '2026-11-12');
        [$first, $second] = $this->deliveredTaxDocumentsOf($advance);

        $this->refund($advance, '1000.00', '2026-11-18');

        $ofFirst = $this->onlyCorrectionOf($first);
        $ofSecond = $this->onlyCorrectionOf($second);
        self::assertSame([-39500, -60500], [$ofFirst->gross()->minor(), $ofSecond->gross()->minor()]);
        self::assertLessThan($ofFirst->numberKey(), $ofSecond->numberKey(), 'the latest payment is corrected first');
    }

    public function testARefundOfTheLatestPaymentLeavesAnEarlierTaxDocumentNotDelivered(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $this->pay($advance, '605.00', '2026-11-10');
        $this->pay($advance, '605.00', '2026-11-12');
        [$first, $second] = $this->taxDocumentsOf($advance);
        $this->delivered->mark($second->id());

        $this->refund($advance, '605.00', '2026-11-18');

        self::assertFalse($this->reloaded($first)->isTrashed());
        self::assertCount(2, $this->taxDocumentsOf($advance), 'none reissued');
        self::assertSame(-60500, $this->onlyCorrectionOf($second)->gross()->minor());
    }

    public function testEachPartRefundIsCorrectedOnItsDay(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $this->pay($advance, '1210.00', '2026-11-10');
        $document = $this->deliveredTaxDocumentOf($advance);

        $this->refund($advance, '302.50', '2026-11-15');
        $this->refund($advance, '302.50', '2026-11-18');

        $corrections = array_map(static fn (Invoice $correction): array => [$correction->decisiveDate()?->format('Y-m-d'), $correction->gross()->minor()], $this->store->correctionsOf($this->reloaded($document)));
        sort($corrections);
        self::assertSame([['2026-11-15', -30250], ['2026-11-18', -30250]], $corrections);
    }

    public function testTheFormOfAWaitingCorrectionOpensWithWhatIsDue(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $document = $this->taxDocumentPaidOn($advance, '1210.00');
        self::assertTrue(self::getContainer()->get(AdvanceDocuments::class)->claimDeduction($document->id(), $this->issue->issue($this->input())->id()));

        $this->refund($advance, '605.00', '2026-11-18');

        self::assertSame([60500], array_map(static fn (LineInput $line): int => $line->price->minor(), $this->corrections()->linesFor($this->reloaded($document))));
    }

    public function testTheFormOfACorrectedTaxDocumentOpensEmpty(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $payment = $this->pay($advance, '1210.00', '2026-11-10');
        $document = $this->deliveredTaxDocumentOf($advance);
        $this->cancel->cancel($payment->id());

        self::assertSame([], $this->corrections()->linesFor($this->reloaded($document)));
    }

    public function testTheWaitingOnesGoBySoonestDeadline(): void
    {
        $corrected = $this->advance([$this->line('1000.00')]);
        $payments = [$this->pay($corrected, '1210.00', '2026-11-10')];
        $advances = [];
        foreach (['a', 'b', 'c'] as $name) {
            $advances[$name] = $this->advance([$this->line('1000.00')]);
            $payments[] = $this->pay($advances[$name], '1210.00', '2026-11-10');
        }
        $documents = [];
        foreach ([$corrected, ...array_values($advances)] as $advance) {
            $documents[] = $this->deliveredTaxDocumentOf($advance);
        }
        foreach (array_slice($documents, 1) as $document) {
            self::assertTrue(self::getContainer()->get(AdvanceDocuments::class)->claimDeduction($document->id(), $this->issue->issue($this->input())->id()));
        }

        // The corrected one comes first among the candidates and has nothing due; b is cancelled first, c last.
        foreach ([0 => '2026-11-20', 2 => '2026-11-21', 1 => '2026-11-23', 3 => '2026-11-25'] as $index => $day) {
            Clock::set(new MockClock($day.' 09:00:00', 'UTC'));
            $this->cancel->cancel($payments[$index]->id());
        }

        self::assertSame([$documents[2]->id(), $documents[1]->id(), $documents[3]->id()], array_map(static fn (UncorrectedTaxDocument $waiting): string => $waiting->documentId, $this->waiting()));
    }

    /**
     * A correction that went to the trash leaves its tax document waiting: the form
     * opens with what is due, dated on the day of the fact, not on the day it opens.
     */
    public function testTheFormOfAWaitingTaxDocumentOpensOnTheDayOfTheFact(): void
    {
        FirmAccounts::add($this->em, $this->firm, self::SECOND_IBAN);
        $this->em->flush();
        $advance = $this->advance([$this->line('1000.00')], ['bankAccount' => self::SECOND_IBAN]);
        $payment = $this->pay($advance, '1210.00', '2026-11-10');
        $document = $this->deliveredTaxDocumentOf($advance);
        Clock::set(new MockClock('2026-11-25 09:00:00', 'UTC'));
        $this->cancel->cancel($payment->id());
        $this->trash->trash($this->onlyCorrectionOf($document)->id(), 'Issued twice');
        Clock::set(new MockClock('2026-12-01 09:00:00', 'UTC'));
        $this->em->clear();
        $document = $this->store->get($document->id());

        $draft = self::getContainer()->get(CorrectInvoice::class)->valuesFrom($document);

        self::assertSame($document->id(), $draft->correctedInvoiceId);
        self::assertSame($this->customer->id(), $draft->customerId);
        self::assertSame(self::SECOND_IBAN, $draft->bankAccount);
        self::assertSame($document->variableSymbol(), $draft->variableSymbol);
        self::assertSame(['2026-11-25', '2026-11-25', '2026-11-25'], [$draft->issueDate->format('Y-m-d'), $draft->taxPointDate->format('Y-m-d'), $draft->dueDate->format('Y-m-d')]);
    }

    private function deliveredTaxDocumentOf(Invoice $advance): Invoice
    {
        $document = $this->onlyTaxDocumentOf($advance);
        $this->delivered->mark($document->id());
        $this->em->clear();

        return $document;
    }

    /**
     * @return list<Invoice>
     */
    private function deliveredTaxDocumentsOf(Invoice $advance): array
    {
        $documents = $this->taxDocumentsOf($advance);
        foreach ($documents as $document) {
            $this->delivered->mark($document->id());
        }
        $this->em->clear();

        return $documents;
    }

    private function corrections(): CorrectPaymentTaxDocument
    {
        return self::getContainer()->get(CorrectPaymentTaxDocument::class);
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
        $line = new LineInput($line->text, Quantity::fromString(str_starts_with($gross, '-') ? '-1' : '1'), $line->unit, $line->price, $line->priceType, $line->treatment, $line->rateKind, $line->supplyCode, $line->discount);

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
