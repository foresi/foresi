<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Application;

use App\Banking\Application\FirmPairingRules;
use App\Banking\Application\MovementFilter;
use App\Banking\Application\MovementInput;
use App\Banking\Application\PairingChoices;
use App\Banking\Application\PairingRules;
use App\Banking\Application\ParsedMovement;
use App\Banking\Application\RecordMovement;
use App\Banking\Domain\MessageTrust;
use App\Banking\Domain\MovementDirection;
use App\Banking\Domain\MovementState;
use App\Banking\Domain\UnmatchedReason;
use App\Country\CzechRepublic\Party\CzechBankAccount;
use App\Invoicing\Application\ConflictRetry;
use App\Invoicing\Application\PaymentState;
use App\Party\Application\BankAccountRefusal;
use App\Party\Application\BankAccountRefused;
use App\Party\Application\BankAccountUsage;
use App\Party\Application\FirmBankAccounts;
use App\Party\Application\LegalEntities;
use App\Party\Application\ManageFirmBankAccounts;
use App\Party\Application\TransactionalWork;
use App\Party\Application\UnknownFirmBankAccount;
use App\Party\Domain\LegalEntity;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Banking\Support\BankingTestCase;
use App\Tests\Banking\Support\LoggingBankMovements;
use App\Tests\Party\Support\FirmAccounts;
use Psr\Clock\ClockInterface;

final class RecordMovementTest extends BankingTestCase
{
    private const string OTHER_OWN_IBAN = 'DE89370400440532013000';

    public function testAVerifiedMovementIsPairedAndTheInvoiceIsPaid(): void
    {
        $invoice = $this->issue->issue($this->input());

        $movement = $this->recordMovement->record($this->incoming('1210.00', messageId: '01963c5e-0000-7000-8000-000000000001'), self::verified());
        $payment = $this->pairing->auto($movement->id());

        self::assertNotNull($payment);
        $this->em->clear();
        $connection = $this->em->getConnection();
        $row = $connection->fetchAssociative('SELECT legal_entity_id, firm_bank_account_id, inbound_message_id, direction, amount_minor, currency, booked_on, counter_iban, variable_symbol, constant_symbol, trust, trust_basis, state, unmatched_reason, payment_id, auto_pair_blocked FROM bank_movement WHERE id = ?', [$movement->id()]);
        self::assertIsArray($row);
        self::assertSame(
            [$this->firm->id(), $this->account->id(), '01963c5e-0000-7000-8000-000000000001', 'incoming', 121000, 'CZK', '2026-11-20', self::COUNTER_IBAN, '20260001', '0308', 'verified', 'dmarc', 'paired', null, $payment->id(), false],
            [$row['legal_entity_id'], $row['firm_bank_account_id'], $row['inbound_message_id'], $row['direction'], self::int($row['amount_minor']), $row['currency'], $row['booked_on'], $row['counter_iban'], $row['variable_symbol'], $row['constant_symbol'], $row['trust'], $row['trust_basis'], $row['state'], $row['unmatched_reason'], $row['payment_id'], (bool) $row['auto_pair_blocked']],
        );
        $stored = $connection->fetchAssociative('SELECT source, bank_movement_id, method, received_on, amount_minor FROM payment WHERE id = ?', [$payment->id()]);
        self::assertSame(['bank_movement', $movement->id(), 'bank_transfer', '2026-11-20', 121000], [$stored['source'] ?? null, $stored['bank_movement_id'] ?? null, $stored['method'] ?? null, $stored['received_on'] ?? null, self::int($stored['amount_minor'] ?? 0)]);
        $allocations = $connection->fetchAllAssociative('SELECT invoice_id, amount_minor, settlement_minor FROM payment_allocation WHERE payment_id = ?', [$payment->id()]);
        self::assertSame([[$invoice->id(), 121000, 0]], array_map(static fn (array $a): array => [$a['invoice_id'], self::int($a['amount_minor']), self::int($a['settlement_minor'])], $allocations));
        self::assertSame(PaymentState::Paid, $this->stateOf($invoice));
    }

    public function testAnUnverifiedMovementIsRecordedUnmatchedWithSuggestions(): void
    {
        $invoice = $this->issue->issue($this->input());

        $movement = $this->recordMovement->record($this->incoming('1210.00'), self::unverified());
        self::assertNull($this->pairing->auto($movement->id()));

        $stored = $this->storedMovement($movement->id());
        self::assertSame([MovementState::Unmatched, UnmatchedReason::Unverified, MessageTrust::Unverified, 'no-results', null], [$stored->state(), $stored->unmatchedReason(), $stored->trust(), $stored->trustBasis(), $stored->paymentId()]);
        self::assertSame([$invoice->id()], array_map(static fn (\App\Banking\Application\Candidate $candidate): string => $candidate->invoiceId, $this->matcher->candidates($stored)));
        self::assertSame(PaymentState::Unpaid, $this->stateOf($invoice));
        self::assertSame(0, $this->paymentCount());
    }

    public function testAutoPairingOffKeepsItUnmatched(): void
    {
        $invoice = $this->issue->issue($this->input());
        $rules = new class implements FirmPairingRules {
            public function for(LegalEntity $firm): PairingRules
            {
                return new PairingRules(false, false);
            }
        };
        $container = self::getContainer();
        $record = new RecordMovement($this->movements, $container->get(FirmBankAccounts::class), $this->matcher, $rules, $container->get(TransactionalWork::class), $container->get(ConflictRetry::class), $container->get(PairingChoices::class));

        $movement = $record->record($this->incoming('1210.00'), self::verified());

        $stored = $this->storedMovement($movement->id());
        self::assertSame([MovementState::Unmatched, UnmatchedReason::AutoPairingOff], [$stored->state(), $stored->unmatchedReason()]);
        self::assertSame(PaymentState::Unpaid, $this->stateOf($invoice));
    }

    public function testTheAccountIsLockedBeforeTheMovementIsAdded(): void
    {
        $movements = new LoggingBankMovements($this->movements);
        $container = self::getContainer();
        $record = new RecordMovement($movements, $container->get(FirmBankAccounts::class), $this->matcher, $container->get(FirmPairingRules::class), $container->get(TransactionalWork::class), $container->get(ConflictRetry::class), $container->get(PairingChoices::class));

        $movement = $record->record($this->incoming('10.00'), self::verified());

        // Movements of one account are recorded one after the other: the second of two equal ones sees the first.
        self::assertSame(['lockAccount:'.$this->account->id(), 'add:'.$movement->id()], $movements->entries);
    }

    public function testAnOwnTransferIsNeverPaired(): void
    {
        $this->issue->issue($this->input());
        FirmAccounts::add($this->em, $this->firm, self::OTHER_OWN_IBAN, Currency::EUR);
        $this->em->flush();

        $movement = $this->recordMovement->record($this->incoming('1210.00', counterIban: 'de89 3704 0044 0532 0130 00'), self::verified());
        self::assertNull($this->pairing->auto($movement->id()));

        $stored = $this->storedMovement($movement->id());
        self::assertSame([MovementState::OwnTransfer, null, null], [$stored->state(), $stored->unmatchedReason(), $stored->paymentId()]);
        self::assertSame(0, $this->paymentCount());
    }

    public function testATransferFromAnotherFirmOfTheTenantPairsNormally(): void
    {
        $invoice = $this->issue->issue($this->input());
        $other = $this->firm(vatId: 'CZ12345687', registrationNumber: '12345687');
        FirmAccounts::add($this->em, $other, self::OTHER_OWN_IBAN);
        $this->em->flush();

        $movement = $this->recordMovement->record($this->incoming('1210.00', counterIban: self::OTHER_OWN_IBAN), self::verified());
        $this->pairing->auto($movement->id());

        self::assertSame(MovementState::Paired, $this->storedMovement($movement->id())->state());
        self::assertSame(PaymentState::Paid, $this->stateOf($invoice));
    }

    public function testAnOutgoingMovementNoRefundFitsIsRecordedOnlyAndQueuedForNoOne(): void
    {
        $this->issue->issue($this->input());

        $movement = $this->recordMovement->record($this->incoming('1210.00', direction: MovementDirection::Outgoing), self::verified());
        self::assertNull($this->pairing->auto($movement->id()));

        $stored = $this->storedMovement($movement->id());
        self::assertSame([MovementState::Recorded, null, MovementDirection::Outgoing], [$stored->state(), $stored->unmatchedReason(), $stored->direction()]);
        self::assertSame(0, $this->paymentCount());
        self::assertSame(0, $this->movements->count(new MovementFilter(MovementFilter::OUTGOING_UNMATCHED)), 'nothing waits in the queue');
    }

    public function testTheSameFingerprintFromAnotherMessageIsAPossibleDuplicate(): void
    {
        $invoice = $this->issue->issue($this->input(overrides: ['lines' => [$this->line(price: '500.00')]]));
        $first = $this->recordMovement->record($this->incoming('100.00', messageId: '01963c5e-0000-7000-8000-000000000001'), self::verified());
        $sameMessage = $this->recordMovement->record($this->incoming('100.00', messageId: '01963c5e-0000-7000-8000-000000000001'), self::verified());
        $otherMessage = $this->recordMovement->record($this->incoming('100.00', messageId: '01963c5e-0000-7000-8000-000000000002'), self::verified());
        $otherDay = $this->recordMovement->record($this->incoming('100.00', messageId: '01963c5e-0000-7000-8000-000000000003', bookedOn: '2026-11-19'), self::verified());

        foreach ([$first, $sameMessage, $otherMessage, $otherDay] as $movement) {
            $this->pairing->auto($movement->id());
        }

        self::assertSame(
            [MovementState::Paired, MovementState::Paired, MovementState::PossibleDuplicate, MovementState::Paired],
            array_map(fn (\App\Banking\Domain\BankMovement $movement): MovementState => $this->storedMovement($movement->id())->state(), [$first, $sameMessage, $otherMessage, $otherDay]),
        );
        self::assertSame(30000, $this->payments->paidOf($this->reloaded($invoice))->minor());
    }

    public function testMovementInputComesFromAParsedMovementCut(): void
    {
        $parsed = new ParsedMovement(MovementDirection::Incoming, Money::of('10.00', Currency::CZK), self::IBAN, '123/0800', null, str_repeat('N', 300), new \DateTimeImmutable('2026-11-20'), '1', null, null, str_repeat('m', 600), null);

        $input = MovementInput::of($parsed, $this->account->id(), null);

        self::assertSame([255, 500, '1'], [mb_strlen($input->counterName ?? ''), mb_strlen($input->message ?? ''), $input->variableSymbol]);
        self::assertSame($input->fingerprint(), MovementInput::of($parsed, $this->account->id(), '01963c5e-0000-7000-8000-000000000009')->fingerprint(), 'the message does not count');
        self::assertNotSame($input->fingerprint(), MovementInput::of($parsed, '01963c5e-0000-7000-8000-00000000000a', null)->fingerprint(), 'the account does');
    }

    public function testCurrencyBecomesLockedAfterTheFirstMovement(): void
    {
        $container = self::getContainer();
        $usage = $container->get(BankAccountUsage::class);
        $manage = new ManageFirmBankAccounts($container->get(LegalEntities::class), $container->get(FirmBankAccounts::class), $container->get(TransactionalWork::class), $usage, $container->get(ClockInterface::class), [new CzechBankAccount()]);
        $manage->change($this->account->id(), 'Main', 'EUR', true);
        $manage->change($this->account->id(), 'Main', 'CZK', true);

        $this->recordMovement->record($this->incoming('10.00', vs: null), self::verified());

        try {
            $manage->change($this->account->id(), 'Main', 'EUR', true);
            self::fail('The currency should be locked.');
        } catch (BankAccountRefused $refused) {
            self::assertSame(BankAccountRefusal::CurrencyLocked, $refused->reason);
        }
        $this->em->clear();
        self::assertSame(Currency::CZK, self::getContainer()->get(FirmBankAccounts::class)->get($this->account->id())->currency());
    }

    public function testAMovementInAnotherCurrencyThanItsAccountStaysUnmatchedAndLocksNothing(): void
    {
        // An invoice the movement would pay, were it not on a CZK account.
        $invoice = $this->issue->issue($this->input());
        $this->em->getConnection()->update('invoice', ['currency' => 'EUR'], ['id' => $invoice->id()]);
        $usage = self::getContainer()->get(BankAccountUsage::class);

        $movement = $this->recordMovement->record($this->incoming('1210.00', currency: Currency::EUR), self::verified());

        self::assertSame([MovementState::Unmatched, UnmatchedReason::AccountCurrency], [$movement->state(), $movement->unmatchedReason()]);
        self::assertNull($this->pairing->auto($movement->id()), 'never paired by itself');
        $stored = $this->storedMovement($movement->id());
        self::assertSame([MovementState::Unmatched, UnmatchedReason::AccountCurrency], [$stored->state(), $stored->unmatchedReason()]);
        self::assertSame(0, $this->paymentCount());
        self::assertFalse($usage->hasMovements($this->account->id()), 'the account\'s currency may still be put right');
        self::assertSame('Pohyb je v jiné měně, než má účet. Zkontrolujte měnu účtu.', self::getContainer()->get('translator')->getCatalogue('cs')->get('bank.movement.reason.'.UnmatchedReason::AccountCurrency->value, 'messages+intl-icu'));

        $this->recordMovement->record($this->incoming('10.00', vs: null), self::verified());

        self::assertTrue($usage->hasMovements($this->account->id()), 'a movement in the account\'s currency locks it');
    }

    public function testAnotherTenantsAccountIsRefused(): void
    {
        $account = $this->account->id();
        self::getContainer()->get(TenantContext::class)->switchTo(TenantId::generate());

        $this->expectException(UnknownFirmBankAccount::class);
        $this->recordMovement->record($this->incoming('10.00', accountId: $account), self::verified());
    }
}
