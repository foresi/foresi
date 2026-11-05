<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure;

use App\Invoicing\Application\AllocationRequest;
use App\Invoicing\Application\CancelPayment;
use App\Invoicing\Application\InvoiceBalances;
use App\Invoicing\Application\PaymentRequest;
use App\Invoicing\Application\RecordPayment;
use App\Invoicing\Domain\Payment;
use App\Invoicing\Domain\PaymentSource;
use App\Invoicing\Infrastructure\Doctrine\OwingInvoiceCondition;
use App\Invoicing\Infrastructure\Doctrine\PaymentBalances;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tests\Invoicing\Application\InvoiceBalancesContract;
use Doctrine\DBAL\Connection;

/**
 * The balances from recorded payments: an invoice owes its payable less what the
 * allocations of its payments not cancelled add up to, settlements included.
 */
final class PaymentBalancesTest extends InvoiceBalancesContract
{
    protected function balances(): InvoiceBalances
    {
        return self::getContainer()->get('test.invoice_balances');
    }

    protected function settle(string $invoiceId): void
    {
        $this->pay($invoiceId, '1210.00');
    }

    public function testItIsBoundAndAuthoritative(): void
    {
        self::assertInstanceOf(PaymentBalances::class, $this->balances());
        self::assertSame($this->balances(), self::getContainer()->get(OwingInvoiceCondition::class), 'the SQL condition comes from the same knowledge');
        self::assertTrue($this->balances()->isAuthoritative());
    }

    public function testAPartPaymentLeavesTheRest(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->pay($invoice->id(), '400.00');
        $this->pay($invoice->id(), '10.50');
        $this->em->clear();

        self::assertSame(79950, $this->balances()->outstanding([$invoice->id()])[$invoice->id()]->minor());
    }

    public function testAnOverpaidInvoiceOwesNothingRatherThanLess(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->pay($invoice->id(), '1500.00');
        $this->em->clear();

        self::assertSame(0, $this->balances()->outstanding([$invoice->id()])[$invoice->id()]->minor());
    }

    public function testACancelledPaymentNoLongerCounts(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->pay($invoice->id(), '200.00');
        $cancelled = $this->pay($invoice->id(), '1010.00');
        self::getContainer()->get(CancelPayment::class)->cancel($cancelled->id());
        $this->em->clear();

        self::assertSame(101000, $this->balances()->outstanding([$invoice->id()])[$invoice->id()]->minor());
    }

    public function testASettlementClosesTheInvoiceLikeAPayment(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->pay($invoice->id(), '1209.50');
        // As the bank pairing writes a difference off: the allocation keeps what came, the settlement the rest.
        $this->connection()->executeStatement('UPDATE payment_allocation SET settlement_minor = 50 WHERE invoice_id = ?', [$invoice->id()]);
        $this->em->clear();

        self::assertSame(0, $this->balances()->outstanding([$invoice->id()])[$invoice->id()]->minor());
    }

    public function testTheConditionLetsThroughWhatOwesAndLeavesOutWhatIsPaidOrTrashed(): void
    {
        $open = $this->issue->issue($this->input());
        $partly = $this->issue->issue($this->input());
        $paid = $this->issue->issue($this->input());
        $overpaid = $this->issue->issue($this->input());
        $cancelled = $this->issue->issue($this->input());
        $trashed = $this->issue->issue($this->input());
        $this->pay($partly->id(), '1209.99');
        $this->pay($paid->id(), '1210.00');
        $this->pay($overpaid->id(), '1210.01');
        self::getContainer()->get(CancelPayment::class)->cancel($this->pay($cancelled->id(), '1210.00')->id());
        $this->trashInvoice($trashed->id());
        $this->em->clear();

        $condition = self::getContainer()->get(OwingInvoiceCondition::class)->owing('inv');
        $owing = $this->connection()->fetchFirstColumn('SELECT inv.id FROM invoice inv WHERE '.$condition);

        self::assertEqualsCanonicalizing([$open->id(), $partly->id(), $cancelled->id()], $owing);
    }

    public function testTheConditionIgnoresAllocationsOfAnotherTenant(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->pay($invoice->id(), '1210.00');
        // A row no tenant of this invoice wrote cannot pay it.
        $this->connection()->executeStatement("UPDATE payment_allocation SET tenant_id = '01963c5e-0000-7000-8000-0000000000ee'");
        $this->em->clear();

        $owing = $this->connection()->fetchFirstColumn('SELECT i.id FROM invoice i WHERE '.self::getContainer()->get(OwingInvoiceCondition::class)->owing('i'));

        self::assertSame([$invoice->id()], $owing);
        self::assertSame(121000, $this->balances()->outstanding([$invoice->id()])[$invoice->id()]->minor());
    }

    public function testAPaymentOfAnotherTenantRowIsIgnored(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->pay($invoice->id(), '1210.00');
        // The allocation is this tenant's, its payment row claims another one.
        $this->connection()->executeStatement("UPDATE payment SET tenant_id = '01963c5e-0000-7000-8000-0000000000ee'");
        $this->em->clear();

        self::assertSame(121000, $this->balances()->outstanding([$invoice->id()])[$invoice->id()]->minor());
        self::assertSame([$invoice->id()], $this->connection()->fetchFirstColumn('SELECT i.id FROM invoice i WHERE '.self::getContainer()->get(OwingInvoiceCondition::class)->owing('i')));
    }

    public function testAnInvoiceInAnotherCurrencyOwesInItsOwn(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->connection()->executeStatement("UPDATE invoice SET currency = 'EUR' WHERE id = ?", [$invoice->id()]);
        $this->em->clear();

        $owed = $this->balances()->outstanding([$invoice->id()])[$invoice->id()];

        self::assertSame(Currency::EUR, $owed->currency());
        self::assertSame(121000, $owed->minor());
    }

    public function testACreditNoteLowersWhatIsLeftOnAnUnpaidInvoice(): void
    {
        $invoice = $this->issue->issue($this->input());
        $credit = $this->correctionOf($invoice, '-400.00');
        $this->em->clear();

        $owed = $this->balances()->outstanding([$invoice->id(), $credit->id()]);

        self::assertSame(72600, $owed[$invoice->id()]->minor(), '1 210 less 484');
        self::assertSame(0, $owed[$credit->id()]->minor(), 'the credit note owes nothing itself');
    }

    public function testACreditNoteLowersWhatIsLeftOnAPartPaidInvoice(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->pay($invoice->id(), '500.00');
        $this->correctionOf($invoice, '-400.00');
        $this->em->clear();

        self::assertSame(22600, $this->balances()->outstanding([$invoice->id()])[$invoice->id()]->minor());
    }

    public function testACreditNoteOnAPaidInvoiceLeavesNothingToPay(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->pay($invoice->id(), '1210.00');
        $this->correctionOf($invoice, '-400.00');
        $this->em->clear();

        self::assertSame(0, $this->balances()->outstanding([$invoice->id()])[$invoice->id()]->minor());
    }

    public function testADebitNoteRaisesWhatIsLeftOnItsInvoiceAndOwesNothingItself(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->pay($invoice->id(), '1210.00');
        $debit = $this->correctionOf($invoice, '200.00');
        $this->em->clear();

        $owed = $this->balances()->outstanding([$invoice->id(), $debit->id()]);

        self::assertSame(24200, $owed[$invoice->id()]->minor());
        self::assertSame(0, $owed[$debit->id()]->minor());
    }

    public function testATrashedCorrectionNoLongerCounts(): void
    {
        $invoice = $this->issue->issue($this->input());
        $credit = $this->correctionOf($invoice, '-400.00');
        $this->trashInvoice($credit->id());
        $this->em->clear();

        self::assertSame(121000, $this->balances()->outstanding([$invoice->id()])[$invoice->id()]->minor());
    }

    public function testTheConditionFollowsTheCorrections(): void
    {
        $credited = $this->issue->issue($this->input());
        $this->correctionOf($credited, '-1000.00');
        $partly = $this->issue->issue($this->input());
        $this->correctionOf($partly, '-400.00');
        $debited = $this->issue->issue($this->input());
        $this->pay($debited->id(), '1210.00');
        $this->correctionOf($debited, '200.00');
        $this->em->clear();

        $owing = $this->connection()->fetchFirstColumn('SELECT inv.id FROM invoice inv WHERE '.self::getContainer()->get(OwingInvoiceCondition::class)->owing('inv'));

        self::assertEqualsCanonicalizing([$partly->id(), $debited->id()], $owing, 'a fully credited invoice and the corrections themselves owe nothing');
        self::assertSame(0, $this->balances()->outstanding([$credited->id()])[$credited->id()]->minor());
    }

    private function pay(string $invoiceId, string $amount): Payment
    {
        $czk = Money::of($amount, Currency::CZK);
        $payment = self::getContainer()->get(RecordPayment::class)->record(new PaymentRequest(
            $this->firm->id(),
            new \DateTimeImmutable('2026-11-01'),
            $czk,
            PaymentMethod::BankTransfer,
            PaymentSource::Manual,
            null,
            '',
            [new AllocationRequest($invoiceId, $czk)],
        ));
        $this->em->clear();

        return $payment;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
