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
use App\Invoicing\Application\InvoicePayments;
use App\Invoicing\Application\InvoicingCountries;
use App\Invoicing\Application\PaymentRefused;
use App\Invoicing\Application\PaymentRequest;
use App\Invoicing\Application\PaymentState;
use App\Invoicing\Application\RecordPayment;
use App\Invoicing\Application\UnknownInvoice;
use App\Invoicing\Domain\PaymentSource;
use App\Invoicing\Domain\PriceType;
use App\Party\Application\TransactionalWork;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
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
        $payment = $this->pairing->pair($movement->id(), [new AllocationRequest($invoice->id(), $this->czk('1210.00'))], checkedInBank: true);

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

    public function testAnUnverifiedMovementPairsOnlyOnceAPersonCheckedItInTheBank(): void
    {
        $invoice = $this->issue->issue($this->input());
        $unverified = $this->recordMovement->record($this->incoming('1210.00'), self::unverified());
        $accepted = $this->recordMovement->record($this->incoming('1210.00', bookedOn: '2026-11-19'), new TrustVerdict(MessageTrust::Unverified, TrustVerdict::ACCEPTED_BY_USER));
        $allocation = [new AllocationRequest($invoice->id(), $this->czk('1210.00'))];

        $this->expectPairingRefused(PairingRefused::NOT_CHECKED_IN_BANK, fn (): \App\Invoicing\Domain\Payment => $this->pairing->pair($unverified->id(), $allocation));
        $this->expectPairingRefused(PairingRefused::NOT_CHECKED_IN_BANK, fn (): \App\Invoicing\Domain\Payment => $this->pairing->pair($accepted->id(), $allocation, checkedInBank: false));
        self::assertSame(0, $this->paymentCount());
        self::assertSame(MovementState::Unmatched, $this->storedMovement($unverified->id())->state());

        $this->pairing->pair($unverified->id(), $allocation, checkedInBank: true);
        self::assertSame(MovementState::Paired, $this->storedMovement($unverified->id())->state());
        self::assertSame(1, $this->paymentCount());
    }

    /**
     * A failed trust is refused for itself, as an application rule, not only for
     * the reason the matcher stored, which another change may replace.
     */
    public function testAFailedTrustIsRefusedWhateverReasonTheMovementStands(): void
    {
        $invoice = $this->issue->issue($this->input());
        $failed = $this->recordMovement->record($this->incoming('1210.00'), new TrustVerdict(MessageTrust::Failed, TrustVerdict::DKIM_FAIL));
        $this->em->getConnection()->executeStatement("UPDATE bank_movement SET unmatched_reason = 'no_invoice' WHERE id = ?", [$failed->id()]);
        $this->em->clear();

        $this->expectPairingRefused(PairingRefused::REJECTED, fn (): \App\Invoicing\Domain\Payment => $this->pairing->pair($failed->id(), [new AllocationRequest($invoice->id(), $this->czk('1210.00'))], checkedInBank: true));
        self::assertSame(0, $this->paymentCount());
    }

    public function testAnInvoicePaidAlreadyIsRefused(): void
    {
        $invoice = $this->issue->issue($this->input());
        $first = $this->recordMovement->record($this->incoming('1210.00'), self::verified());
        self::assertNotNull($this->pairing->auto($first->id()));
        self::assertSame(PaymentState::Paid, $this->stateOf($invoice));
        $second = $this->recordMovement->record($this->incoming('1210.00', vs: null, bookedOn: '2026-11-19'), self::verified());

        $this->expectPairingRefused(PairingRefused::INVOICE_SETTLED, fn (): \App\Invoicing\Domain\Payment => $this->pairing->pair($second->id(), [new AllocationRequest($invoice->id(), $this->czk('1210.00'))]));
        self::assertSame(1, $this->paymentCount());
        self::assertSame(MovementState::Unmatched, $this->storedMovement($second->id())->state());
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

        self::assertCount(4, array_filter($log->entries, static fn (string $entry): bool => str_starts_with($entry, 'read:')), 'the first read and one after each of the three rounds of locks');
        $stored = $this->storedMovement($movement->id());
        self::assertSame([MovementState::Unmatched, UnmatchedReason::Ambiguous], [$stored->state(), $stored->unmatchedReason()]);
        self::assertSame(0, $this->paymentCount());
    }

    public function testAPaymentTheInvoiceRefusesLeavesTheMovementUnmatchedWithTheReason(): void
    {
        $this->issue->issue($this->input());
        // Booked tomorrow, as a bank in another time zone may date it: no payment is received in the future.
        $movement = $this->recordMovement->record($this->incoming('1210.00', bookedOn: '2026-11-21'), self::verified());

        self::assertNull($this->pairing->auto($movement->id()));

        $stored = $this->storedMovement($movement->id());
        self::assertSame([MovementState::Unmatched, UnmatchedReason::Refused, null], [$stored->state(), $stored->unmatchedReason(), $stored->paymentId()]);
        self::assertSame(0, $this->paymentCount());
        self::assertNull($this->pairing->auto($movement->id()), 'a second look refuses the same and changes nothing');
        self::assertSame(UnmatchedReason::Refused, $this->storedMovement($movement->id())->unmatchedReason());
        self::assertSame('Faktura platbu nepřijala. Spárujte ji ručně.', self::getContainer()->get('translator')->getCatalogue('cs')->get('bank.movement.reason.'.UnmatchedReason::Refused->value, 'messages+intl-icu'));
    }

    public function testARefusedCurrencyKeepsItsOwnReason(): void
    {
        $invoice = $this->issue->issue($this->input());
        $movement = $this->recordMovement->record($this->incoming('1210.00'), self::verified());
        // The matcher saw a CZK invoice; by the time it pays, the invoice says EUR.
        $this->em->getConnection()->update('invoice', ['currency' => 'EUR'], ['id' => $invoice->id()]);
        $log = new LockLog(self::getContainer()->get(Candidates::class), static fn (int $read, array $found): array => array_map(
            static fn (Candidate $candidate): Candidate => new Candidate($candidate->invoiceId, $candidate->number, $candidate->dueDate, Money::ofMinor($candidate->remaining->minor(), Currency::CZK), $candidate->customerName, $candidate->issueDate),
            $found,
        ));

        self::assertNull($this->pairingWith($log)->auto($movement->id()));

        self::assertSame(UnmatchedReason::CurrencyMismatch, $this->storedMovement($movement->id())->unmatchedReason());
        self::assertSame(0, $this->paymentCount());
    }

    public function testAnotherTenantsMovementIsNotFound(): void
    {
        $movement = $this->recordMovement->record($this->incoming('10.00'), self::verified());
        self::getContainer()->get(TenantContext::class)->switchTo(TenantId::generate());

        $this->expectException(UnknownBankMovement::class);
        $this->pairing->auto($movement->id());
    }

    public function testAnInvoiceThatDropsOutOfALaterReadStaysLocked(): void
    {
        $first = $this->issue->issue($this->input());
        $second = $this->issue->issue($this->input());
        $movement = $this->recordMovement->record($this->incoming('1210.00'), self::verified());
        // The first read finds the first invoice only, every later one the second only, as if the first was paid meanwhile.
        $log = new LockLog(self::getContainer()->get(Candidates::class), static fn (int $read, array $found): array => array_values(array_filter(
            $found,
            static fn (Candidate $candidate): bool => $candidate->invoiceId === (1 === $read ? $first : $second)->id(),
        )));

        self::assertNotNull($this->pairingWith($log)->auto($movement->id()));

        $both = [$first->id(), $second->id()];
        sort($both, \SORT_STRING);
        self::assertSame(['read:'.$first->id(), 'lock:'.$first->id(), 'read:'.$second->id(), 'lock:'.$both[0], 'lock:'.$both[1], 'read:'.$second->id()], $log->entries);
        self::assertSame(PaymentState::Paid, $this->stateOf($second));
    }

    public function testInvoicesAreLockedInAscendingIdOrder(): void
    {
        $first = $this->issue->issue($this->input());
        $second = $this->issue->issue($this->input());
        $ids = [$first->id(), $second->id()];
        sort($ids, \SORT_STRING);
        $movement = $this->recordMovement->record($this->incoming('1210.00', vs: null), self::verified());
        $log = new LockLog(self::getContainer()->get(Candidates::class));

        $this->pairingWith($log)->pair($movement->id(), [new AllocationRequest($ids[1], $this->czk('605.00')), new AllocationRequest($ids[0], $this->czk('605.00'))]);

        self::assertSame(['lock:'.$ids[0], 'lock:'.$ids[1]], $log->entries);
    }

    public function testAMovementMarkedPairedIsRefusedEvenWithoutALivePayment(): void
    {
        $invoice = $this->issue->issue($this->input());
        $movement = $this->recordMovement->record($this->incoming('1210.00'), self::verified());
        $this->em->getConnection()->executeStatement("UPDATE bank_movement SET state = 'paired', unmatched_reason = NULL WHERE id = ?", [$movement->id()]);
        $this->em->clear();

        $this->expectPairingRefused(PairingRefused::ALREADY_PAIRED, fn (): \App\Invoicing\Domain\Payment => $this->pairing->pair($movement->id(), [new AllocationRequest($invoice->id(), $this->czk('1210.00'))]));
        self::assertSame(0, $this->paymentCount());
    }

    public function testALivePaymentFromAnotherPathRefusesBeforeTheInvoiceIsLookedAt(): void
    {
        $invoice = $this->issue->issue($this->input());
        $movement = $this->recordMovement->record($this->incoming('1210.00'), self::verified());
        $this->payFromAnotherPath($movement->id(), $invoice->id());

        $this->expectPairingRefused(PairingRefused::ALREADY_PAIRED, fn (): \App\Invoicing\Domain\Payment => $this->pairing->pair($movement->id(), [new AllocationRequest($invoice->id(), $this->czk('1210.00'))]));
    }

    public function testARejectedMovementIsRefusedWhateverItsTrustSays(): void
    {
        $invoice = $this->issue->issue($this->input());
        $movement = $this->recordMovement->record($this->incoming('1210.00'), self::verified());
        $this->em->getConnection()->executeStatement("UPDATE bank_movement SET unmatched_reason = 'rejected' WHERE id = ?", [$movement->id()]);
        $this->em->clear();

        $this->expectPairingRefused(PairingRefused::REJECTED, fn (): \App\Invoicing\Domain\Payment => $this->pairing->pair($movement->id(), [new AllocationRequest($invoice->id(), $this->czk('1210.00'))], checkedInBank: true));
        self::assertSame(0, $this->paymentCount());
    }

    public function testAMovementNoLongerPairedIsNotUnpairedThroughThePaymentItStillNames(): void
    {
        $this->issue->issue($this->input());
        $movement = $this->recordMovement->record($this->incoming('1210.00'), self::verified());
        $payment = $this->pairing->auto($movement->id());
        self::assertNotNull($payment);
        $this->em->getConnection()->executeStatement("UPDATE bank_movement SET state = 'unmatched', unmatched_reason = 'no_invoice' WHERE id = ?", [$movement->id()]);
        $this->em->clear();

        $this->expectPairingRefused(PairingRefused::NOT_PAIRED, fn () => $this->unpairing->unpair($movement->id()));
        self::assertFalse($this->payments->get($payment->id())->isCancelled());
    }

    public function testARefusalStandsWhenAnotherPathPaidTheMovementMeanwhile(): void
    {
        $invoice = $this->issue->issue($this->input());
        $other = $this->issue->issue($this->input());
        $unverified = $this->recordMovement->record($this->incoming('1210.00'), self::unverified());
        $short = $this->recordMovement->record($this->incoming('1210.00', bookedOn: '2026-11-19'), self::verified());
        $unknownInvoice = $this->recordMovement->record($this->incoming('1210.00', bookedOn: '2026-11-18'), self::verified());

        $this->expectPairingRefused(PairingRefused::NOT_CHECKED_IN_BANK, fn (): \App\Invoicing\Domain\Payment => $this->pairingMeanwhile(fn () => $this->payFromAnotherPath($unverified->id(), $other->id(), '1.00'))->pair($unverified->id(), [new AllocationRequest($invoice->id(), $this->czk('1210.00'))]));
        try {
            $this->pairingMeanwhile(fn () => $this->payFromAnotherPath($short->id(), $other->id(), '1.00'))->pair($short->id(), [new AllocationRequest($invoice->id(), $this->czk('1000.00'))]);
            self::fail('A wrong sum should be refused.');
        } catch (PaymentRefused $refused) {
            self::assertSame(PaymentRefused::AMOUNT_MISMATCH, $refused->reason);
        }
        try {
            $this->pairingMeanwhile(fn () => $this->payFromAnotherPath($unknownInvoice->id(), $other->id(), '1.00'))->pair($unknownInvoice->id(), [new AllocationRequest('01963c5e-0000-7000-8000-00000000dead', $this->czk('1210.00'))]);
            self::fail('An unknown invoice should not be found.');
        } catch (UnknownInvoice) {
        }
        self::assertSame(3, $this->paymentCount(), 'only the payments of the other path');
    }

    public function testAFailureAfterAnotherPathPaidTheMovementMeansItIsPaidAlready(): void
    {
        $invoice = $this->issue->issue($this->input());
        $manual = $this->recordMovement->record($this->incoming('1210.00'), self::verified());
        $automatic = $this->recordMovement->record($this->incoming('1210.00', bookedOn: '2026-11-19'), self::verified());
        $unpaid = $this->recordMovement->record($this->incoming('1210.00', bookedOn: '2026-11-18'), self::verified());
        $lost = new \RuntimeException('The unique index of live payments refused the insert.');

        $this->expectPairingRefused(PairingRefused::ALREADY_PAIRED, fn (): \App\Invoicing\Domain\Payment => $this->pairingMeanwhile(fn () => $this->payFromAnotherPath($manual->id(), $invoice->id(), '1.00'), $lost)->pair($manual->id(), [new AllocationRequest($invoice->id(), $this->czk('1210.00'))]));
        self::assertNull($this->pairingMeanwhile(fn () => $this->payFromAnotherPath($automatic->id(), $invoice->id(), '1.00'), $lost)->auto($automatic->id()));

        try {
            $this->pairingMeanwhile(static function (): void {}, $lost)->auto($unpaid->id());
            self::fail('Should the movement still be unpaid, the failure is not hidden.');
        } catch (\RuntimeException $failure) {
            self::assertSame($lost, $failure);
        }
    }

    public function testAMovementThatDoesNotExistStaysUnknownWhateverPaymentNamesIt(): void
    {
        $invoice = $this->issue->issue($this->input());
        $missing = '01963c5e-0000-7000-8000-00000000beef';
        $this->payFromAnotherPath($missing, $invoice->id(), '1.00');

        try {
            $this->pairing->pair($missing, [new AllocationRequest($invoice->id(), $this->czk('1.00'))]);
            self::fail('The movement should not be found.');
        } catch (UnknownBankMovement $unknown) {
            self::assertSame('The bank movement does not exist.', $unknown->getMessage());
        }
        $this->expectException(UnknownBankMovement::class);
        $this->pairing->auto($missing);
    }

    public function testAPaymentRefusedAfterAnotherPathPaidTheMovementLeavesItsReason(): void
    {
        $invoice = $this->issue->issue($this->input());
        // Booked tomorrow: the invoice refuses the payment.
        $movement = $this->recordMovement->record($this->incoming('1210.00', bookedOn: '2026-11-21'), self::verified());
        $before = $this->storedMovement($movement->id())->unmatchedReason();

        self::assertNull($this->pairingMeanwhile(fn () => $this->payFromAnotherPath($movement->id(), $invoice->id(), '1.00'))->auto($movement->id()));

        self::assertNotSame(UnmatchedReason::Refused, $before);
        self::assertSame($before, $this->storedMovement($movement->id())->unmatchedReason(), 'the payment of the other path stands, and the reason with it');
    }

    public function testATrashedInvoiceTheMatcherChoseLeavesTheMovementWithoutAnInvoice(): void
    {
        $trashed = $this->issue->issue($this->input());
        $this->trash->trash($trashed->id(), 'Issued by mistake');
        $movement = $this->recordMovement->record($this->incoming('1210.00'), self::verified());
        $this->em->getConnection()->executeStatement("UPDATE bank_movement SET unmatched_reason = 'ambiguous' WHERE id = ?", [$movement->id()]);
        $this->em->clear();
        // The matcher read it just before it was trashed.
        $log = new LockLog(self::getContainer()->get(Candidates::class), fn (int $read, array $found): array => [new Candidate($trashed->id(), $trashed->number(), new \DateTimeImmutable('2026-11-15'), $this->czk('1210.00'), 'Example Customer a.s.', new \DateTimeImmutable('2026-11-01'))]);

        self::assertNull($this->pairingWith($log)->auto($movement->id()));

        self::assertSame(UnmatchedReason::NoInvoice, $this->storedMovement($movement->id())->unmatchedReason());
        self::assertSame(0, $this->paymentCount());
    }

    public function testARefusalSaysWhy(): void
    {
        self::assertSame('The pairing was refused: not_paired.', new PairingRefused(PairingRefused::NOT_PAIRED)->getMessage());
    }

    /**
     * Pairing whose first unit lets another path pay the movement once it failed, or
     * before it fails with the given failure, as a notice committing meanwhile would.
     */
    private function pairingMeanwhile(\Closure $meanwhile, ?\Throwable $failure = null): PairMovement
    {
        $container = self::getContainer();
        $work = new class($container->get(TransactionalWork::class), $meanwhile, $failure) implements TransactionalWork {
            private bool $done = false;

            public function __construct(private readonly TransactionalWork $work, private readonly \Closure $meanwhile, private readonly ?\Throwable $failure)
            {
            }

            public function run(callable $work): mixed
            {
                if ($this->done) {
                    return $this->work->run($work);
                }
                $this->done = true;
                if ($this->failure instanceof \Throwable) {
                    ($this->meanwhile)();

                    throw $this->failure;
                }
                try {
                    return $this->work->run($work);
                } catch (\Throwable $failure) {
                    ($this->meanwhile)();

                    throw $failure;
                }
            }
        };

        return new PairMovement(
            $this->movements,
            $this->matcher,
            $container->get(FirmPairingRules::class),
            $container->get(RecordPayment::class),
            $this->store,
            $work,
            $container->get(ConflictRetry::class),
            $container->get(InvoicePayments::class),
        );
    }

    private function payFromAnotherPath(string $movementId, string $invoiceId, string $amount = '1210.00'): void
    {
        self::getContainer()->get(RecordPayment::class)->record(new PaymentRequest($this->firm->id(), new \DateTimeImmutable('2026-11-20'), $this->czk($amount), PaymentMethod::BankTransfer, PaymentSource::BankMovement, $movementId, '', [new AllocationRequest($invoiceId, $this->czk($amount))]));
        $this->em->flush();
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
            $container->get(InvoicePayments::class),
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
