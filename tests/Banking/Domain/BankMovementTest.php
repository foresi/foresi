<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Domain;

use App\Banking\Domain\BankMovement;
use App\Banking\Domain\MessageTrust;
use App\Banking\Domain\MovementDirection;
use App\Banking\Domain\MovementState;
use App\Banking\Domain\UnmatchedReason;
use App\Party\Domain\Address;
use App\Party\Domain\FirmBankAccount;
use App\Party\Domain\Iban;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a movement accepts when it is recorded, and how its version follows where it stands.
 */
final class BankMovementTest extends TestCase
{
    private const string FINGERPRINT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testANegativeAmountIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A movement amount is positive; the direction carries the sign.');
        $this->movement(amount: '-1.00');
    }

    public function testAZeroAmountIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A movement amount is positive; the direction carries the sign.');
        $this->movement(amount: '0.00');
    }

    public function testValuesUpToTheirColumnsAreKept(): void
    {
        $movement = $this->movement(
            counterAccount: str_repeat('1', BankMovement::MAX_COUNTER_ACCOUNT),
            counterIban: str_repeat('C', 34),
            // Characters, not bytes: a name of accented letters fills its column by length.
            counterName: str_repeat('ř', BankMovement::MAX_COUNTER_NAME),
            message: str_repeat('é', BankMovement::MAX_MESSAGE),
        );

        self::assertSame(str_repeat('1', BankMovement::MAX_COUNTER_ACCOUNT), $movement->counterAccount());
        self::assertSame(str_repeat('C', 34), $movement->counterIban());
        self::assertSame(str_repeat('ř', BankMovement::MAX_COUNTER_NAME), $movement->counterName());
        self::assertSame(str_repeat('é', BankMovement::MAX_MESSAGE), $movement->message());
        self::assertSame(1, $movement->version());
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function tooLong(): iterable
    {
        yield 'counter account' => [['counterAccount' => str_repeat('1', BankMovement::MAX_COUNTER_ACCOUNT + 1)]];
        yield 'counter IBAN' => [['counterIban' => str_repeat('C', 35)]];
        yield 'counter name' => [['counterName' => str_repeat('x', BankMovement::MAX_COUNTER_NAME + 1)]];
        yield 'message' => [['message' => str_repeat('x', BankMovement::MAX_MESSAGE + 1)]];
    }

    /**
     * @param array<string, string> $values
     */
    #[DataProvider('tooLong')]
    public function testAValuePastItsColumnIsRefused(array $values): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A value of the movement is longer than its column.');
        $this->movement(...$values);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badFingerprints(): iterable
    {
        yield 'something after the hash' => [self::FINGERPRINT.'0'];
        yield 'something before the hash' => ['0'.self::FINGERPRINT];
        yield 'a line break after the hash' => [self::FINGERPRINT."\n"];
        yield 'upper case' => [strtoupper(self::FINGERPRINT)];
    }

    #[DataProvider('badFingerprints')]
    public function testAFingerprintIsExactlyASha256InLowerCaseHex(string $fingerprint): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A fingerprint is a sha256 in lowercase hex.');
        $this->movement(fingerprint: $fingerprint);
    }

    public function testAnUnmatchedMovementNeedsAReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('An unmatched movement has a reason, and only an unmatched one does.');
        $this->movement(reason: null);
    }

    public function testOnlyAnUnmatchedMovementHasAReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('An unmatched movement has a reason, and only an unmatched one does.');
        $this->movement(state: MovementState::Recorded);
    }

    public function testPairingRaisesTheVersion(): void
    {
        $movement = $this->movement();

        $movement->pairWith('payment-1');

        self::assertSame(MovementState::Paired, $movement->state());
        self::assertNull($movement->unmatchedReason());
        self::assertSame('payment-1', $movement->paymentId());
        self::assertSame(2, $movement->version());
    }

    public function testANewReasonRaisesTheVersionAndTheSameOneDoesNot(): void
    {
        $movement = $this->movement();

        $movement->stayUnmatched(UnmatchedReason::NoInvoice);
        self::assertSame(1, $movement->version());

        $movement->stayUnmatched(UnmatchedReason::Ambiguous);
        self::assertSame(UnmatchedReason::Ambiguous, $movement->unmatchedReason());
        self::assertSame(2, $movement->version());
    }

    public function testReleasingRaisesTheVersionAndBlocksAutoPairing(): void
    {
        $movement = $this->movement();
        $movement->pairWith('payment-1');

        $movement->release();

        self::assertSame(MovementState::Unmatched, $movement->state());
        self::assertSame(UnmatchedReason::Blocked, $movement->unmatchedReason());
        self::assertNull($movement->paymentId());
        self::assertTrue($movement->isAutoPairBlocked());
        self::assertSame(3, $movement->version());
    }

    public function testARecordedOutgoingMovementPairsWithItsRefundAndIsRecordedAgainOnRelease(): void
    {
        $movement = $this->movement(state: MovementState::Recorded, reason: null, direction: MovementDirection::Outgoing);

        $movement->pairWith('refund-1');
        self::assertSame(MovementState::Paired, $movement->state());
        self::assertSame(2, $movement->version());

        $movement->release();

        self::assertSame(MovementState::Recorded, $movement->state());
        self::assertNull($movement->unmatchedReason());
        self::assertNull($movement->paymentId());
        self::assertFalse($movement->isAutoPairBlocked());
        self::assertSame(3, $movement->version());
    }

    public function testARecordedIncomingMovementIsNotPaired(): void
    {
        $movement = $this->movement(state: MovementState::Recorded, reason: null);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('Only an unmatched movement is paired.');
        $movement->pairWith('payment-1');
    }

    public function testAPairedMovementIsNotPairedAgain(): void
    {
        $movement = $this->movement(state: MovementState::Recorded, reason: null, direction: MovementDirection::Outgoing);
        $movement->pairWith('refund-1');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('Only an unmatched movement is paired.');
        $movement->pairWith('refund-2');
    }

    public function testTheTenantIsOnlyKnownOncePersisted(): void
    {
        $movement = $this->movement();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('The tenant is stamped on persist.');
        $movement->tenantId();
    }

    private function movement(
        string $amount = '100.00',
        ?string $counterAccount = null,
        ?string $counterIban = null,
        ?string $counterName = null,
        ?string $message = null,
        string $fingerprint = self::FINGERPRINT,
        MovementState $state = MovementState::Unmatched,
        ?UnmatchedReason $reason = UnmatchedReason::NoInvoice,
        MovementDirection $direction = MovementDirection::Incoming,
    ): BankMovement {
        $firm = new LegalEntity('Example Firm', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
        $account = FirmBankAccount::open($firm, Iban::fromString('CZ6508000000192000145399'), Currency::CZK, '', 0);

        return new BankMovement($account, null, $direction, Money::of($amount, Currency::CZK), new \DateTimeImmutable('2026-11-20'), $counterAccount, $counterIban, $counterName, null, null, null, $message, null, $fingerprint, MessageTrust::Verified, 'dmarc', $state, $reason);
    }
}
