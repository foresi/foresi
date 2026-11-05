<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\IssuePaymentTaxDocument;
use App\Invoicing\Application\IssueRefused;
use App\Invoicing\Application\MissingTaxDocument;
use App\Invoicing\Application\MissingTaxDocuments;
use App\Invoicing\Application\RecordPayment;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\TaxOnReceiptChoice;
use App\Invoicing\Domain\TaxTreatment;
use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyKind;
use App\Party\Domain\VatId;

/**
 * The paid advances that still need a tax document, the days left for each, and
 * the document issued by hand from there.
 */
final class MissingTaxDocumentsTest extends AdvanceTestCase
{
    public function testAPaymentWhoseDocumentWasNotIssuedWaitsWithItsDeadline(): void
    {
        $advance = $this->advanceNotKnownYet();
        $payment = $this->pay($advance, '1210.00', '2026-11-12');
        $this->knownNow($advance);

        $missing = $this->missingOn('2026-11-20');

        self::assertCount(1, $missing);
        self::assertSame([$advance->id(), 'Z920260001', 'Example Customer a.s.', 'Example Firm s.r.o.', $payment->id()], [$missing[0]->advanceId, $missing[0]->advanceNumber, $missing[0]->customerName, $missing[0]->firmName, $missing[0]->paymentId]);
        self::assertSame(['2026-11-12', '2026-11-27', 7, 121000], [$missing[0]->receivedOn->format('Y-m-d'), $missing[0]->deadline->format('Y-m-d'), $missing[0]->daysLeft, $missing[0]->received->minor()]);
        self::assertFalse($missing[0]->isLate());
        self::assertSame(-3, $this->missingOn('2026-11-30 23:59')[0]->daysLeft);
        self::assertTrue($this->missingOn('2026-11-30')[0]->isLate());
        self::assertSame(0, $this->missingOn('2026-11-27')[0]->daysLeft);
    }

    public function testAnAdvanceWithoutVatOnReceiptNeverWaits(): void
    {
        $this->pay($this->advanceNotKnownYet(), '1210.00');
        $person = new Party(PartyKind::Person, 'Jana Example', null, null, '');
        $this->em->persist($person);
        $this->em->persist(new PartyAddress($person, AddressType::Billing, new Address('Home Street 1', 'Home City', '100 00', 'CZ')));
        $this->em->flush();
        $this->pay($this->advance([$this->line('1000.00')], ['customerId' => $person->id()]), '1210.00');

        self::assertSame([], $this->missingOn('2026-11-20'));
    }

    public function testAPaymentWithItsDocumentDoesNotWait(): void
    {
        $this->pay($this->advance([$this->line('1000.00')]), '1210.00');

        self::assertSame([], $this->missingOn('2026-11-20'));
    }

    public function testACancelledPaymentDropsOut(): void
    {
        $advance = $this->advanceNotKnownYet();
        $payment = $this->pay($advance, '1210.00');
        $this->knownNow($advance);

        $this->cancel->cancel($payment->id());

        self::assertSame([], $this->missingOn('2026-11-20'));
    }

    public function testAnAdvanceInTheTrashDoesNotWait(): void
    {
        $advance = $this->advanceNotKnownYet();
        $this->knownNow($advance);
        $this->trash->trash($advance->id(), 'Issued by mistake');
        $this->em->getConnection()->executeStatement('UPDATE invoice SET deleted_at = NULL WHERE id = ?', [$advance->id()]);
        $this->pay($this->reloaded($advance), '1210.00');
        $this->em->getConnection()->executeStatement("UPDATE invoice SET deleted_at = '2026-11-20 10:00:00' WHERE id = ?", [$advance->id()]);

        self::assertSame([], $this->missingOn('2026-11-20'));
    }

    public function testACrossBorderPaymentWaitsUntilFifteenDaysAfterTheMonth(): void
    {
        $german = new Party(PartyKind::Company, 'Example Buyer GmbH', null, VatId::fromString('DE123456789'), '');
        $this->em->persist($german);
        $this->em->persist(new PartyAddress($german, AddressType::Billing, new Address('Beispielstrasse 1', 'Berlin', '10115', 'DE')));
        $this->em->flush();
        $advance = $this->advance([$this->line('2000.00', treatment: TaxTreatment::ReverseCharge, kind: null)], ['customerId' => $german->id(), 'taxOnReceipt' => TaxOnReceiptChoice::Skip]);
        $this->pay($advance, '2000.00', '2026-11-12');
        $this->knownNow($advance);

        self::assertSame('2026-12-15', $this->missingOn('2026-11-20')[0]->deadline->format('Y-m-d'));
    }

    public function testTheSoonestDeadlineComesFirst(): void
    {
        $later = $this->advanceNotKnownYet();
        $sooner = $this->advanceNotKnownYet();
        $this->pay($later, '1210.00', '2026-11-15');
        $this->pay($sooner, '1210.00', '2026-11-10');
        $this->knownNow($later);
        $this->knownNow($sooner);

        self::assertSame([$sooner->id(), $later->id()], array_map(static fn (MissingTaxDocument $row): string => $row->advanceId, $this->missingOn('2026-11-20')));
    }

    public function testADocumentIssuedByHandEndsTheWait(): void
    {
        $advance = $this->advanceNotKnownYet();
        $payment = $this->pay($advance, '1210.00', '2026-11-12');
        $this->knownNow($advance);

        $document = $this->issuing()->byHand($advance->id(), $payment->id());

        self::assertSame(DocumentKind::PaymentTaxDocument, $document->kind());
        self::assertSame('2026-11-12', $document->taxPointDate()?->format('Y-m-d'));
        self::assertSame([['21.00', 100000, 21000, 121000]], $this->figuresOf($this->onlyTaxDocumentOf($advance)));
        self::assertSame([], $this->missingOn('2026-11-20'));
    }

    public function testIssuingByHandTwiceIsRefused(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $payment = $this->pay($advance, '1210.00');

        self::assertSame(['invoice.problem.tax_document_exists'], $this->refusalsOf(fn (): Invoice => $this->issuing()->byHand($advance->id(), $payment->id())));
    }

    public function testIssuingByHandWithoutVatIsRefused(): void
    {
        $advance = $this->advanceNotKnownYet();
        $payment = $this->pay($advance, '1210.00');
        $invoice = $this->issue->issue($this->input());
        $paid = $this->pay($invoice, '1210.00');

        self::assertSame(['invoice.problem.no_tax_on_receipt'], $this->refusalsOf(fn (): Invoice => $this->issuing()->byHand($advance->id(), $payment->id())));
        self::assertSame(['invoice.problem.no_tax_on_receipt'], $this->refusalsOf(fn (): Invoice => $this->issuing()->byHand($invoice->id(), $paid->id())));
    }

    public function testIssuingByHandForAnotherPaymentIsRefused(): void
    {
        $advance = $this->advance([$this->line('1000.00')], ['taxOnReceipt' => TaxOnReceiptChoice::Issue]);
        $other = $this->pay($this->issue->issue($this->input()), '1210.00');

        self::assertSame(['invoice.problem.payment_unknown'], $this->refusalsOf(fn (): Invoice => $this->issuing()->byHand($advance->id(), $other->id())));
    }

    private function advanceNotKnownYet(): Invoice
    {
        return $this->advance([$this->line('1000.00')], ['taxOnReceipt' => TaxOnReceiptChoice::Skip]);
    }

    /**
     * The supply became known after the payment: by the rules now, VAT arose.
     */
    private function knownNow(Invoice $advance): void
    {
        $this->em->getConnection()->executeStatement('UPDATE invoice SET tax_on_receipt = NULL WHERE id = ?', [$advance->id()]);
        $this->em->clear();
    }

    /**
     * @return list<MissingTaxDocument>
     */
    private function missingOn(string $day): array
    {
        $this->em->clear();

        return self::getContainer()->get(MissingTaxDocuments::class)->on(new \DateTimeImmutable($day));
    }

    private function issuing(): IssuePaymentTaxDocument
    {
        $service = new \ReflectionProperty(RecordPayment::class, 'taxDocuments')->getValue($this->record);
        self::assertInstanceOf(IssuePaymentTaxDocument::class, $service);

        return $service;
    }

    /**
     * @return list<string>
     */
    private function refusalsOf(callable $issue): array
    {
        try {
            $issue();
        } catch (IssueRefused $refused) {
            return array_map(static fn (\App\Invoicing\Application\IssueProblem $problem): string => $problem->key, $refused->problems);
        }
        self::fail('Expected a refusal.');
    }
}
