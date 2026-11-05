<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\AllocationRequest;
use App\Invoicing\Application\CancelPayment;
use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\InvoicePayments;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\PaymentRequest;
use App\Invoicing\Application\RecordPayment;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceLine;
use App\Invoicing\Domain\Payment;
use App\Invoicing\Domain\PaymentSource;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\VatRateKind;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * Advance invoices of the payer firm, their payments and the tax documents those
 * get, on a fresh schema, on 20 November 2026.
 */
abstract class AdvanceTestCase extends InvoiceTestCase
{
    protected RecordPayment $record;
    protected CancelPayment $cancel;
    protected InvoicePayments $payments;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::set(new MockClock('2026-11-20 10:00:00', 'UTC'));
        $container = self::getContainer();
        $this->record = $container->get(RecordPayment::class);
        $this->cancel = $container->get(CancelPayment::class);
        $this->payments = $container->get(InvoicePayments::class);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    /**
     * @param list<LineInput>|null $lines
     * @param array<string, mixed> $overrides named arguments of InvoiceInput
     */
    protected function advance(?array $lines = null, array $overrides = []): Invoice
    {
        return $this->issue->issue($this->advanceInput($lines, $overrides));
    }

    /**
     * @param list<LineInput>|null $lines
     * @param array<string, mixed> $overrides named arguments of InvoiceInput
     */
    protected function advanceInput(?array $lines = null, array $overrides = []): InvoiceInput
    {
        return $this->input($lines, [...['seriesId' => null, 'kind' => DocumentKind::Advance, 'variableSymbolFollowsNumber' => true, 'variableSymbol' => ''], ...$overrides]);
    }

    /**
     * A line priced with VAT, as an advance on an agreed gross amount is.
     */
    protected function gross(string $price, ?VatRateKind $kind = VatRateKind::Base, string $text = 'Milestone'): LineInput
    {
        return $this->line($price, PriceType::WithVat, kind: $kind, text: $text);
    }

    /**
     * @param array<string, string> $more other invoices the payment pays, amount by id
     */
    protected function pay(Invoice $invoice, string $amount, string $on = '2026-11-20', PaymentMethod $method = PaymentMethod::BankTransfer, array $more = []): Payment
    {
        $allocations = [new AllocationRequest($invoice->id(), $this->czk($amount))];
        $total = $this->czk($amount);
        foreach ($more as $id => $part) {
            $allocations[] = new AllocationRequest($id, $this->czk($part));
            $total = $total->plus($this->czk($part));
        }

        return $this->record->record(new PaymentRequest($invoice->firm()->id(), new \DateTimeImmutable($on), $total, $method, PaymentSource::Manual, null, '', $allocations));
    }

    /**
     * @return list<Invoice> the tax documents on payments of the advance, by number
     */
    protected function taxDocumentsOf(Invoice $advance): array
    {
        $this->em->clear();

        return $this->em->createQuery('SELECT i FROM '.Invoice::class.' i WHERE i.advanceInvoiceId = :advance ORDER BY i.numberKey')->setParameter('advance', $advance->id())->getResult();
    }

    protected function onlyTaxDocumentOf(Invoice $advance): Invoice
    {
        $documents = $this->taxDocumentsOf($advance);
        self::assertCount(1, $documents);

        return $documents[0];
    }

    /**
     * @return list<array{string|null, int, int, int}> rate, base, VAT and gross in minor units per line
     */
    protected function figuresOf(Invoice $document): array
    {
        return array_map(static fn (InvoiceLine $line): array => [$line->rate()?->decimal(), $line->net()->minor(), $line->vat()->minor(), $line->gross()->minor()], $this->store->lines($document));
    }

    protected function czk(string $amount): Money
    {
        return Money::of($amount, Currency::CZK);
    }
}
