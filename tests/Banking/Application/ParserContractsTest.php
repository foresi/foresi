<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Application;

use App\Banking\Application\DefaultPairingRules;
use App\Banking\Application\ParsedMovement;
use App\Banking\Application\ParseResult;
use App\Banking\Application\TrustVerdict;
use App\Banking\Domain\MessageTrust;
use App\Banking\Domain\MovementDirection;
use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParserContractsTest extends TestCase
{
    public function testAResultCarriesItsMovements(): void
    {
        $first = $this->movement(Money::ofMinor(123450, Currency::CZK));
        $second = $this->movement(Money::ofMinor(100, Currency::CZK));

        $result = ParseResult::withMovements([$first, $second]);

        self::assertSame([$first, $second], $result->movements());
        self::assertNull($result->ignoredReason());
    }

    public function testAnIgnoredResultHasNoMovements(): void
    {
        $result = ParseResult::ignored('a balance report, not a movement');

        self::assertSame([], $result->movements());
        self::assertSame('a balance report, not a movement', $result->ignoredReason());
    }

    public function testAResultWithoutMovementsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ParseResult::withMovements([]);
    }

    public function testAnIgnoredResultNeedsAReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ParseResult::ignored(' ');
    }

    public function testAMovementKeepsWhatTheBankWrote(): void
    {
        $movement = $this->movement(Money::ofMinor(123450, Currency::CZK));

        self::assertSame(MovementDirection::Incoming, $movement->direction);
        self::assertSame(123450, $movement->amount->minor());
        self::assertSame('CZ6508000000192000145399', $movement->ownAccount);
        self::assertSame('123-4567890123/0100', $movement->counterAccount);
        self::assertSame('2026-11-02', $movement->bookedOn->format('Y-m-d'));
        self::assertSame('2026001', $movement->variableSymbol);
    }

    /**
     * @return iterable<string, array{Money}>
     */
    public static function notPositive(): iterable
    {
        yield 'zero' => [Money::ofMinor(0, Currency::CZK)];
        yield 'negative' => [Money::ofMinor(-1, Currency::CZK)];
    }

    #[DataProvider('notPositive')]
    public function testAMovementAmountIsPositive(Money $amount): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->movement($amount);
    }

    public function testAVerdictNamesItsBasis(): void
    {
        $verdict = new TrustVerdict(MessageTrust::Verified, 'dkim');

        self::assertSame(MessageTrust::Verified, $verdict->trust);
        self::assertSame('dkim', $verdict->basis);
    }

    public function testAVerdictWithAnUnknownBasisIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TrustVerdict(MessageTrust::Verified, 'looks-fine');
    }

    public function testEveryFirmPairsAutomaticallyAndSettlesNoDifferenceByDefault(): void
    {
        $firm = new LegalEntity('Example s.r.o.', new RegistrationNumber('CZ', '27074358'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');

        $rules = new DefaultPairingRules()->for($firm);

        self::assertTrue($rules->autoPairing);
        self::assertFalse($rules->smallDifference);
    }

    private function movement(Money $amount): ParsedMovement
    {
        return new ParsedMovement(
            direction: MovementDirection::Incoming,
            amount: $amount,
            ownAccount: 'CZ6508000000192000145399',
            counterAccount: '123-4567890123/0100',
            counterIban: null,
            counterName: 'Example Customer',
            bookedOn: new \DateTimeImmutable('2026-11-02'),
            variableSymbol: '2026001',
            constantSymbol: null,
            specificSymbol: null,
            message: null,
            bankReference: null,
        );
    }
}
