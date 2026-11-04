<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Support;

use App\Banking\Application\BankMovements;
use App\Banking\Application\Matcher;
use App\Banking\Application\MovementInput;
use App\Banking\Application\PairMovement;
use App\Banking\Application\RecordMovement;
use App\Banking\Application\TrustVerdict;
use App\Banking\Application\UnpairMovement;
use App\Banking\Domain\BankMovement;
use App\Banking\Domain\MessageTrust;
use App\Banking\Domain\MovementDirection;
use App\Invoicing\Application\InvoicePayments;
use App\Invoicing\Application\PaymentState;
use App\Invoicing\Domain\Invoice;
use App\Party\Application\FirmBankAccounts;
use App\Party\Domain\FirmBankAccount;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tests\Invoicing\Application\InvoiceTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * The invoicing test firm with its account, a clock on 20 November 2026 and the
 * banking services. The default invoice is 1,210.00 CZK with VS 20260001.
 */
abstract class BankingTestCase extends InvoiceTestCase
{
    protected const string COUNTER_IBAN = 'CZ5508000000001234567899';

    protected RecordMovement $recordMovement;
    protected PairMovement $pairing;
    protected UnpairMovement $unpairing;
    protected Matcher $matcher;
    protected BankMovements $movements;
    protected InvoicePayments $payments;
    protected FirmBankAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::set(new MockClock('2026-11-20 12:00:00', 'UTC'));
        $container = self::getContainer();
        $this->recordMovement = $container->get(RecordMovement::class);
        $this->pairing = $container->get(PairMovement::class);
        $this->unpairing = $container->get(UnpairMovement::class);
        $this->matcher = $container->get(Matcher::class);
        $this->movements = $container->get(BankMovements::class);
        $this->payments = $container->get(InvoicePayments::class);
        $account = $container->get(FirmBankAccounts::class)->primaryOf($this->firm);
        self::assertInstanceOf(FirmBankAccount::class, $account);
        $this->account = $account;
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    protected function incoming(
        string $amount,
        ?string $vs = '20260001',
        Currency $currency = Currency::CZK,
        ?string $counterIban = self::COUNTER_IBAN,
        ?string $messageId = null,
        string $bookedOn = '2026-11-20',
        MovementDirection $direction = MovementDirection::Incoming,
        ?string $accountId = null,
    ): MovementInput {
        return new MovementInput(
            $accountId ?? $this->account->id(),
            $messageId,
            $direction,
            Money::of($amount, $currency),
            new \DateTimeImmutable($bookedOn),
            '123456789/0800',
            $counterIban,
            'Example Customer a.s.',
            $vs,
            '0308',
            null,
            'Payment for an invoice',
            null,
        );
    }

    protected static function verified(): TrustVerdict
    {
        return new TrustVerdict(MessageTrust::Verified, TrustVerdict::DMARC);
    }

    protected static function unverified(): TrustVerdict
    {
        return new TrustVerdict(MessageTrust::Unverified, TrustVerdict::NO_RESULTS);
    }

    protected function storedMovement(string $id): BankMovement
    {
        $this->em->clear();

        return $this->movements->get($id);
    }

    protected function stateOf(Invoice $invoice): PaymentState
    {
        $stored = $this->reloaded($invoice);

        return PaymentState::of($stored->payable(), $this->payments->paidOf($stored));
    }

    protected function paymentCount(): int
    {
        return self::int($this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM payment'));
    }

    protected static function int(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }

    protected function czk(string $amount): Money
    {
        return Money::of($amount, Currency::CZK);
    }
}
