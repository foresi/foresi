<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Domain;

use Foresi\Banking\Contract\MovementDirection;
use Foresi\Banking\Contract\MovementTrust;
use Foresi\Banking\Domain\BankMovement;
use Foresi\Banking\Domain\MovementState;
use Foresi\Banking\Domain\UnmatchedReason;
use Foresi\Party\Domain\FirmBankAccount;
use Foresi\Party\Domain\Iban;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
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

    public function testARecordedOutgoingMovementPairsWithItsRefundAndIsBlockedUnmatchedOnRelease(): void
    {
        $movement = $this->movement(state: MovementState::Recorded, reason: null, direction: MovementDirection::Outgoing);

        $movement->pairWith('refund-1');
        self::assertSame(MovementState::Paired, $movement->state());
        self::assertSame(2, $movement->version());

        $movement->release();

        self::assertSame(MovementState::Unmatched, $movement->state());
        self::assertSame(UnmatchedReason::Blocked, $movement->unmatchedReason());
        self::assertNull($movement->paymentId());
        self::assertTrue($movement->isAutoPairBlocked());
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

    public function testACorroborationAddsTheOtherSourceAndRaisesTheVersion(): void
    {
        $movement = $this->movement();
        $at = new \DateTimeImmutable('2026-11-21 08:00:00');

        $movement->corroborate('cz.fio', str_repeat('ř', BankMovement::MAX_BANK_REFERENCE), 'statement-1', null, MovementTrust::Unverified, 'unverified_source', $at);

        self::assertTrue($movement->isCorroborated());
        self::assertSame(['cz.fio', str_repeat('ř', BankMovement::MAX_BANK_REFERENCE), 'statement-1', null, $at], [$movement->corroboratedSourceId(), $movement->corroboratedReference(), $movement->corroboratedStatementId(), $movement->corroboratedMessageId(), $movement->corroboratedAt()]);
        self::assertSame([MovementTrust::Verified, 'dmarc'], [$movement->trust(), $movement->trustBasis()]);
        self::assertSame(2, $movement->version());
    }

    public function testACorroborationWithoutAReferenceIsKept(): void
    {
        $movement = $this->movement();

        $movement->corroborate('cz.fio', null, null, 'message-1', MovementTrust::Verified, 'bank_api', new \DateTimeImmutable('2026-11-21'));

        self::assertNull($movement->corroboratedReference());
        self::assertSame('message-1', $movement->corroboratedMessageId());
    }

    public function testATooLongReferenceIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->movement()->corroborate('cz.fio', str_repeat('x', BankMovement::MAX_BANK_REFERENCE + 1), null, null, MovementTrust::Verified, 'bank_api', new \DateTimeImmutable('2026-11-21'));
    }

    public function testAMovementIsCorroboratedOnce(): void
    {
        $movement = $this->movement();
        $movement->corroborate('cz.fio', null, null, null, MovementTrust::Verified, 'bank_api', new \DateTimeImmutable('2026-11-21'));

        $this->expectException(\LogicException::class);
        $movement->corroborate('cz.kb', null, null, null, MovementTrust::Verified, 'bank_api', new \DateTimeImmutable('2026-11-22'));
    }

    public function testAPossibleDuplicateIsNotCorroborated(): void
    {
        $movement = $this->movement(state: MovementState::PossibleDuplicate, reason: null);

        $this->expectException(\LogicException::class);
        $movement->corroborate('cz.fio', null, null, null, MovementTrust::Verified, 'bank_api', new \DateTimeImmutable('2026-11-21'));
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
        $account = FirmBankAccount::open(Iban::fromString('CZ6508000000192000145399'), Currency::CZK, '', 0);

        return new BankMovement($account, null, $direction, Money::of($amount, Currency::CZK), new \DateTimeImmutable('2026-11-20'), $counterAccount, $counterIban, $counterName, null, null, null, $message, null, $fingerprint, MovementTrust::Verified, 'dmarc', $state, $reason);
    }
}
