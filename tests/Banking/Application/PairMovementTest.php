<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Application;

use App\Banking\Application\Candidate;
use App\Banking\Application\Candidates;
use App\Banking\Application\FirmPairingRules;
use App\Banking\Application\Matcher;
use App\Banking\Application\PairingRefused;
use App\Banking\Application\PairingRules;
use App\Banking\Application\PairMovement;
use App\Banking\Application\TrustVerdict;
use App\Banking\Application\UnknownBankMovement;
use App\Banking\Domain\MessageTrust;
use App\Banking\Domain\MovementDirection;
use App\Banking\Domain\MovementState;
use App\Banking\Domain\UnmatchedReason;
use App\Invoicing\Application\AllocationRequest;
use App\Invoicing\Application\CancelPayment;
use App\Invoicing\Application\ConflictRetry;
use App\Invoicing\Application\InvoicingCountries;
use App\Invoicing\Application\PaymentRefused;
use App\Invoicing\Application\PaymentRequest;
use App\Invoicing\Application\PaymentState;
use App\Invoicing\Application\RecordPayment;
use App\Invoicing\Domain\PaymentSource;
use App\Invoicing\Domain\PriceType;
use App\Party\Application\TransactionalWork;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\PaymentMethod;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Banking\Support\BankingTestCase;
use App\Tests\Banking\Support\LockLog;
use App\Tests\Banking\Support\LoggingInvoiceStore;

final class PairMovementTest extends BankingTestCase
{
    public function testUnpairingCancelsThePaymentAndBlocksAutoPairing(): void
    {
        $invoice = $this->issue->issue($this->input());
        $movement = $this->recordMovement->record($this->incoming('1210.00'), self::verified());
        $payment = $this->pairing->auto($movement->id());
        self::assertNotNull($payment);

        $this->unpairing->unpair($movement->id());

        $stored = $this->storedMovement($movement->id());
        self::assertSame([MovementState::Unmatched, UnmatchedReason::Blocked, null, true], [$stored->state(), $stored->unmatchedReason(), $stored->paymentId(), $stored->isAutoPairBlocked()]);
        self::assertTrue($this->payments->get($payment->id())->isCancelled());
        self::assertSame(PaymentState::Unpaid, $this->stateOf($invoice));

        self::assertNull($this->pairing->auto($movement->id()), 'never paired by itself again');
        self::assertSame(UnmatchedReason::Blocked, $this->storedMovement($movement->id())->unmatchedReason());
        self::assertSame(PaymentState::Unpaid, $this->stateOf($invoice));

        $this->expectPairingRefused(PairingRefused::NOT_PAIRED, fn () => $this->unpairing->unpair($movement->id()));
    }

    public function testABlockedMovementCanStillBePairedByHand(): void
    {
        $invoice = $this->issue->issue($this->input());
        $movement = $this->recordMovement->record($this->incoming('1210.00'), self::verified());
        $this->pairing->auto($movement->id());
        $this->unpairing->unpair($movement->id());

        $payment = $this->pairing->pair($movement->id(), [new AllocationRequest($invoice->id(), $this->czk('1210.00'))]);

        $stored = $this->storedMovement($movement->id());
        self::assertSame([MovementState::Paired, $payment->id()], [$stored->state(), $stored->paymentId()]);
        self::assertSame(PaymentState::Paid, $this->stateOf($invoice));
    }

    public function testRemovingTheBankPaymentOnTheInvoiceUnpairsTheMovement(): void
    {
        $invoice = $this->issue->issue($this->input());
        $movement = $this->recordMovement->record($this->incoming('1210.00'), self::verified());
        $payment = $this->pairing->auto($movement->id());
        self::assertNotNull($payment);

        self::getContainer()->get(CancelPayment::class)->cancel($payment->id());

        $stored = $this->storedMovement($movement->id());
        self::assertSame([MovementState::Unmatched, UnmatchedReason::Blocked, null, true], [$stored->state(), $stored->unmatchedReason(), $stored->paymentId(), $stored->isAutoPairBlocked()]);
        self::assertTrue($this->payments->get($payment->id())->isCancelled());
        self::assertSame(1, $this->paymentCount(), 'no second cancellation, no new payment');
        self::assertSame(PaymentState::Unpaid, $this->stateOf($invoice));
    }

    public function testManualPairingOverTwoInvoicesNeedsTheExactSum(): void
    {
        $first = $this->issue->issue($this->input(overrides: ['variableSymbol' => '1']));
        $second = $this->issue->issue($this->input(overrides: ['variableSymbol' => '2']));
        $movement = $this->recordMovement->record($this->incoming('2000.00', vs: null), self::verified());

        try {
            $this->pairing->pair($movement->id(), [new AllocationRequest($first->id(), $this->czk('1210.00')), new AllocationRequest($second->id(), $this->czk('700.00'))]);
            self::fail('A wrong sum should be refused.');
        } catch (PaymentRefused $refused) {
            self::assertSame(PaymentRefused::AMOUNT_MISMATCH, $refused->reason);
        }
        self::assertSame([MovementState::Unmatched, null], [$this->storedMovement($movement->id())->state(), $this->storedMovement($movement->id())->paymentId()]);
        self::assertSame(0, $this->paymentCount());

        $payment = $this->pairing->pair($movement->id(), [new AllocationRequest($second->id(), $this->czk('790.00')), new AllocationRequest($first->id(), $this->czk('1210.00'))]);

        self::assertSame([MovementState::Paired, $payment->id()], [$this->storedMovement($movement->id())->state(), $this->storedMovement($movement->id())->paymentId()]);
        self::assertSame(PaymentState::Paid, $this->stateOf($first));
        self::assertSame(PaymentState::PartlyPaid, $this->stateOf($second));
        self::assertSame(200000, $this->payments->get($payment->id())->amount()->minor());
    }

    public function testPairingTwiceIsRefusedAndAutoAfterAManualPairingDoesNothing(): void
    {
        $invoice = $this->issue->issue($this->input());
        $movement = $this->recordMovement->record($this->incoming('1210.00'), self::unverified());
        $payment = $this->pairing->pair($movement->id(), [new AllocationRequest($invoice->id(), $this->czk('1210.00'))]);

        self::assertNull($this->pairing->auto($movement->id()));
        $this->expectPairingRefused(PairingRefused::ALREADY_PAIRED, fn (): \App\Invoicing\Domain\Payment => $this->pairing->pair($movement->id(), [new AllocationRequest($invoice->id(), $this->czk('1210.00'))]));

        self::assertSame($payment->id(), $this->storedMovement($movement->id())->paymentId());
        self::assertSame(1, $this->paymentCount());
    }

    public function testAMovementWithALivePaymentFromAnotherPathCountsAsPaid(): void
    {
        $invoice = $this->issue->issue($this->input(overrides: ['lines' => [$this->line(price: '5000.00')]]));
        $movement = $this->recordMovement->record($this->incoming('1210.00'), self::verified());
        self::getContainer()->get(RecordPayment::class)->record(new PaymentRequest($this->firm->id(), new \DateTimeImmutable('2026-11-20'), $this->czk('1210.00'), PaymentMethod::BankTransfer, PaymentSource::BankMovement, $movement->id(), '', [new AllocationRequest($invoice->id(), $this->czk('1210.00'))]));

        self::assertNull($this->pairing->auto($movement->id()));
        $this->expectPairingRefused(PairingRefused::ALREADY_PAIRED, fn (): \App\Invoicing\Domain\Payment => $this->pairing->pair($movement->id(), [new AllocationRequest($invoice->id(), $this->czk('1210.00'))]));
        self::assertSame(1, $this->paymentCount());
    }

    public function testUnpairableMovementsAreRefused(): void
    {
        $invoice = $this->issue->issue($this->input());
        $outgoing = $this->recordMovement->record($this->incoming('10.00', direction: MovementDirection::Outgoing), self::verified());
        $this->recordMovement->record($this->incoming('10.00', messageId: '01963c5e-0000-7000-8000-000000000001'), self::verified());
        $duplicate = $this->recordMovement->record($this->incoming('10.00', messageId: '01963c5e-0000-7000-8000-000000000002'), self::verified());
        $rejected = $this->recordMovement->record($this->incoming('11.00'), new TrustVerdict(MessageTrust::Failed, TrustVerdict::DKIM_FAIL));
        $own = $this->recordMovement->record($this->incoming('12.00', counterIban: self::IBAN), self::verified());
        $allocation = [new AllocationRequest($invoice->id(), $this->czk('10.00'))];

        $this->expectPairingRefused(PairingRefused::NOT_INCOMING, fn (): \App\Invoicing\Domain\Payment => $this->pairing->pair($outgoing->id(), $allocation));
        $this->expectPairingRefused(PairingRefused::POSSIBLE_DUPLICATE, fn (): \App\Invoicing\Domain\Payment => $this->pairing->pair($duplicate->id(), $allocation));
        $this->expectPairingRefused(PairingRefused::REJECTED, fn (): \App\Invoicing\Domain\Payment => $this->pairing->pair($rejected->id(), [new AllocationRequest($invoice->id(), $this->czk('11.00'))]));
        $this->expectPairingRefused(PairingRefused::OWN_TRANSFER, fn (): \App\Invoicing\Domain\Payment => $this->pairing->pair($own->id(), [new AllocationRequest($invoice->id(), $this->czk('12.00'))]));
        self::assertSame(UnmatchedReason::Rejected, $this->storedMovement($rejected->id())->unmatchedReason());
        self::assertSame(0, $this->paymentCount());
    }

    public function testSmallDifferenceIsSettledUnderTheLocks(): void
    {
        $invoice = $this->issue->issue($this->input(overrides: ['lines' => [$this->line(price: '1000.00', type: PriceType::WithVat)]]));
        $movement = $this->recordMovement->record($this->incoming('999.01'), self::verified());
        $rules = new class implements FirmPairingRules {
            public function for(LegalEntity $firm): PairingRules
            {
                return new PairingRules(true, true);
            }
        };
        // Just after the first read, 0.50 is paid on the invoice, as by a person on another screen.
        $log = new LockLog(self::getContainer()->get(Candidates::class), function (int $read, array $found) use ($invoice): array {
            if (1 === $read) {
                self::getContainer()->get(RecordPayment::class)->record(new PaymentRequest($this->firm->id(), new \DateTimeImmutable('2026-11-20'), $this->czk('0.50'), PaymentMethod::Cash, PaymentSource::Manual, null, '', [new AllocationRequest($invoice->id(), $this->czk('0.50'))]));
                $this->em->flush();
            }

            return $found;
        });

        $payment = $this->pairingWith($log, $rules)->auto($movement->id());

        self::assertNotNull($payment);
        self::assertSame('lock:'.$invoice->id(), $log->entries[1], 'the decision is made after the lock');
        $allocation = $this->em->getConnection()->fetchAssociative('SELECT amount_minor, settlement_minor FROM payment_allocation WHERE payment_id = ?', [$payment->id()]);
        self::assertSame([99901, 49], [self::int($allocation['amount_minor'] ?? 0), self::int($allocation['settlement_minor'] ?? 0)], 'the settlement is what is left after the 0.50, not what the first read saw');
        self::assertSame(PaymentState::Paid, $this->stateOf($invoice));
    }

    public function testAnInvoiceThatAppearsBetweenTheReadsIsLockedBeforeItIsDecidedOn(): void
    {
        $older = $this->issue->issue($this->input());
        $newer = $this->issue->issue($this->input(overrides: ['lines' => [$this->line(price: '500.00', type: PriceType::WithVat)]]));
        $movement = $this->recordMovement->record($this->incoming('500.00'), self::verified());
        // The first read misses the newer invoice, as if it was issued just after it.
        $log = new LockLog(self::getContainer()->get(Candidates::class), static fn (int $read, array $found): array => 1 === $read
            ? array_values(array_filter($found, static fn (Candidate $candidate): bool => $candidate->invoiceId !== $newer->id()))
            : $found);

        $payment = $this->pairingWith($log)->auto($movement->id());

        self::assertNotNull($payment);
        $both = $older->id().','.$newer->id();
        self::assertSame(['read:'.$older->id(), 'lock:'.$older->id(), 'read:'.$both, 'lock:'.$older->id(), 'lock:'.$newer->id(), 'read:'.$both], $log->entries, 'the decision is made from a read whose invoices were all locked before it');
        self::assertSame(PaymentState::Paid, $this->stateOf($newer), 'the exact amount pays the newer invoice');
        self::assertSame(PaymentState::Unpaid, $this->stateOf($older));
    }

    public function testCandidatesThatNeverSettleLeaveTheMovementAmbiguous(): void
    {
        $this->issue->issue($this->input());
        $movement = $this->recordMovement->record($this->incoming('1210.00'), self::verified());
        $invoices = 0;
        // Every read finds one invoice more than the one before.
        $log = new LockLog(self::getContainer()->get(Candidates::class), function (int $read, array $found) use (&$invoices): array {
            while ($invoices < $read) {
                $this->issue->issue($this->input(overrides: ['lines' => [$this->line(price: (100 + ++$invoices).'.00', type: PriceType::WithVat)]]));
                $this->em->flush();
            }

            return $found;
        });

        self::assertNull($this->pairingWith($log)->auto($movement->id()));

        $stored = $this->storedMovement($movement->id());
        self::assertSame([MovementState::Unmatched, UnmatchedReason::Ambiguous], [$stored->state(), $stored->unmatchedReason()]);
        self::assertSame(0, $this->paymentCount());
    }

    public function testAnotherTenantsMovementIsNotFound(): void
    {
        $movement = $this->recordMovement->record($this->incoming('10.00'), self::verified());
        self::getContainer()->get(TenantContext::class)->switchTo(TenantId::generate());

        $this->expectException(UnknownBankMovement::class);
        $this->pairing->auto($movement->id());
    }

    private function pairingWith(LockLog $log, ?FirmPairingRules $rules = null): PairMovement
    {
        $container = self::getContainer();

        return new PairMovement(
            $this->movements,
            new Matcher($log, $container->get(InvoicingCountries::class)),
            $rules ?? $container->get(FirmPairingRules::class),
            $container->get(RecordPayment::class),
            new LoggingInvoiceStore($this->store, $log),
            $container->get(TransactionalWork::class),
            $container->get(ConflictRetry::class),
        );
    }

    private function expectPairingRefused(string $reason, callable $pair): void
    {
        try {
            $pair();
        } catch (PairingRefused $refused) {
            self::assertSame($reason, $refused->reason);

            return;
        }
        self::fail('Expected the pairing to be refused for '.$reason.'.');
    }
}
