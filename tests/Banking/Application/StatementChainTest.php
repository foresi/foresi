<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Application;

use Foresi\Banking\Application\StatementChain;
use Foresi\Banking\Application\StatementGap;
use Foresi\Banking\Contract\BankStatement;
use Foresi\Banking\Contract\MovementChannel;
use Foresi\Banking\Contract\MovementDirection;
use Foresi\Banking\Contract\MovementProvenance;
use Foresi\Banking\Contract\ParsedMovement;
use Foresi\Banking\Contract\StatementGapKind;
use Foresi\Banking\Contract\StatementSequence;
use Foresi\Banking\Domain\ReceivedStatement;
use Foresi\Party\Domain\FirmBankAccount;
use Foresi\Party\Domain\Iban;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use PHPUnit\Framework\TestCase;

final class StatementChainTest extends TestCase
{
    private FirmBankAccount $account;
    private FirmBankAccount $other;

    protected function setUp(): void
    {
        $this->account = FirmBankAccount::open(Iban::fromString('CZ6508000000192000145399'), Currency::of('CZK'), 'Main', 1);
        $this->other = FirmBankAccount::open(Iban::fromString('CZ5508000000001234567899'), Currency::of('CZK'), 'Savings', 2);
    }

    public function testStatementsThatFollowOnHaveNoGap(): void
    {
        $chain = [
            $this->statement(43, '2026-11', '1000.00', '1210.00', ['210.00']),
            $this->statement(42, '2026-10', '900.00', '1000.00', ['100.00']),
            $this->statement(44, '2026-12', '1210.00', '1200.00', ['-10.00']),
        ];

        self::assertSame([], StatementChain::gaps($chain));
        self::assertSame(0, StatementChain::accountsWithGaps($chain));
        self::assertSame([$chain[1], $chain[0], $chain[2]], StatementChain::ordered($chain));
    }

    public function testAMissingNumberIsANumberGap(): void
    {
        $earlier = $this->statement(42, '2026-10', '900.00', '1000.00', ['100.00']);
        $later = $this->statement(44, '2026-12', '1000.00', '1000.00', []);

        $gaps = StatementChain::gaps([$later, $earlier]);

        self::assertSame([[StatementGapKind::Number, $later, $earlier]], $this->described($gaps));
        self::assertSame(1, StatementChain::accountsWithGaps([$later, $earlier]));
    }

    public function testAnOpeningBalanceThatIsNotThePreviousClosingOneIsABalanceGap(): void
    {
        $earlier = $this->statement(null, '2026-10', '900.00', '1000.00', ['100.00']);
        $later = $this->statement(null, '2026-11', '1400.00', '1400.00', []);

        $gaps = StatementChain::gaps([$earlier, $later]);

        self::assertCount(1, $gaps);
        self::assertSame([StatementGapKind::Balance, $later, $earlier], $this->described($gaps)[0]);
        self::assertTrue(Money::of('1000.00', Currency::of('CZK'))->equals($gaps[0]->expected ?? self::fail()));
        self::assertTrue(Money::of('1400.00', Currency::of('CZK'))->equals($gaps[0]->actual ?? self::fail()));
    }

    public function testANumberGapIsTheOneSaidWhenTheBalanceDiffersToo(): void
    {
        $earlier = $this->statement(42, '2026-10', '900.00', '1000.00', ['100.00']);
        $later = $this->statement(44, '2026-12', '1400.00', '1400.00', []);

        self::assertSame([[StatementGapKind::Number, $later, $earlier]], $this->described(StatementChain::gaps([$earlier, $later])));
    }

    public function testANewYearStartsAtOne(): void
    {
        $december = $this->statement(12, '2026-12', '900.00', '1000.00', ['100.00'], 2026);
        $january = $this->statement(1, '2027-01', '1000.00', '1000.00', [], 2027);
        $february = $this->statement(3, '2027-02', '1000.00', '1000.00', [], 2027);

        self::assertSame([[StatementGapKind::Number, $february, $january]], $this->described(StatementChain::gaps([$february, $january, $december])));
    }

    public function testAStatementWhoseEntriesDoNotAddUpIsAnInnerMismatch(): void
    {
        $cut = $this->statement(42, '2026-10', '900.00', '1000.00', ['50.00']);

        $gaps = StatementChain::gaps([$cut]);

        self::assertSame([[StatementGapKind::Inner, $cut, null]], $this->described($gaps));
        self::assertTrue(Money::of('950.00', Currency::of('CZK'))->equals($gaps[0]->expected ?? self::fail()));
        self::assertTrue(Money::of('1000.00', Currency::of('CZK'))->equals($gaps[0]->actual ?? self::fail()));
        self::assertSame('inner::'.$cut->id(), $gaps[0]->key());
    }

    public function testTwoSourcesOfOneAccountAreTwoChains(): void
    {
        $file = $this->statement(42, '2026-10', '900.00', '1000.00', ['100.00'], source: 'test.json');
        $api = $this->statement(7, '2026-10', '1000.00', '1000.00', [], source: 'cz.fio');
        $nextFile = $this->statement(43, '2026-11', '1000.00', '1000.00', [], source: 'test.json');

        self::assertSame([], StatementChain::gaps([$file, $api, $nextFile]));
        self::assertSame([[$file, $nextFile], [$api]], StatementChain::chains([$file, $api, $nextFile]));
    }

    public function testTwoAccountsAreTwoChainsAndCountedOnce(): void
    {
        $main = $this->statement(42, '2026-10', '900.00', '1000.00', ['50.00']);
        $mainLater = $this->statement(44, '2026-12', '1000.00', '1000.00', []);
        $savings = $this->statement(1, '2026-10', '0.00', '10.00', ['10.00'], account: $this->other);
        $savingsLater = $this->statement(2, '2026-11', '10.00', '10.00', [], account: $this->other);

        self::assertCount(2, StatementChain::gaps([$main, $mainLater, $savings, $savingsLater]));
        self::assertSame(1, StatementChain::accountsWithGaps([$main, $mainLater, $savings, $savingsLater]));
    }

    public function testAStatementWithoutANumberOrABalanceIsNoGapAndBreaksNoChain(): void
    {
        $earlier = $this->statement(42, '2026-10', '900.00', '1000.00', ['100.00']);
        $plain = $this->statement(null, '2026-11', null, null, ['5.00']);
        $later = $this->statement(43, '2026-12', '1000.00', '1000.00', []);

        self::assertSame([], StatementChain::gaps([$earlier, $plain, $later]));
        self::assertSame([[$earlier, $plain, $later]], StatementChain::chains([$later, $plain, $earlier]));
    }

    public function testStatementsOfOnePeriodAreOrderedByNumber(): void
    {
        $second = $this->statement(43, '2026-10', '1000.00', '1000.00', []);
        $first = $this->statement(42, '2026-10', '900.00', '1000.00', ['100.00']);

        self::assertSame([$first, $second], StatementChain::ordered([$second, $first]));
        self::assertSame([], StatementChain::gaps([$second, $first]));
    }

    public function testAStatementOfTheSameNumberAgainTakesThePlaceOfTheOneBefore(): void
    {
        $earlier = $this->statement(41, '2026-09', '800.00', '900.00', ['100.00']);
        $wrong = $this->statement(42, '2026-10', '900.00', '1000.00', ['100.00']);
        $corrected = $this->statement(42, '2026-10', '900.00', '1100.00', ['200.00']);
        $later = $this->statement(43, '2026-11', '1100.00', '1100.00', []);

        self::assertSame([], StatementChain::gaps([$earlier, $wrong, $corrected, $later]));
    }

    public function testAStatementWithANumberAndNoBalanceIsChainedPastAPlainOne(): void
    {
        $plain = $this->statement(null, '2026-11', null, null, ['5.00']);
        $earlier = $this->statement(42, '2026-10', '900.00', '1000.00', ['100.00']);
        $later = $this->statement(44, '2026-12', null, null, []);

        $gaps = StatementChain::gaps([$plain, $earlier, $later]);

        self::assertSame([[StatementGapKind::Number, $later, $earlier]], $this->described($gaps));
        self::assertSame('number:'.$earlier->id().':'.$later->id(), $gaps[0]->key());
    }

    public function testAStatementWithAnOpeningBalanceOnlyIsChainedByIt(): void
    {
        $earlier = $this->statement(42, '2026-10', '900.00', '1000.00', ['100.00']);
        $opening = $this->statement(null, '2026-11', '900.00', null, []);

        self::assertSame([[StatementGapKind::Balance, $opening, $earlier]], $this->described(StatementChain::gaps([$earlier, $opening])));
    }

    public function testAStatementWithoutBalancesFollowingOnHasNoGap(): void
    {
        $earlier = $this->statement(42, '2026-10', '900.00', '1000.00', ['100.00']);
        $later = $this->statement(43, '2026-11', null, null, []);

        self::assertSame([], StatementChain::gaps([$earlier, $later]));
    }

    public function testTwoAccountsOfOneSourceAreTwoChains(): void
    {
        $main = $this->statement(42, '2026-10', '900.00', '1000.00', ['100.00']);
        $savings = $this->statement(1, '2026-10', '0.00', '10.00', ['10.00'], account: $this->other);

        self::assertSame([[$main], [$savings]], StatementChain::chains([$main, $savings]));
    }

    /**
     * @param list<string> $entries signed amounts
     */
    private function statement(?int $number, string $month, ?string $opening, ?string $closing, array $entries, ?int $year = null, string $source = 'test.json', ?FirmBankAccount $account = null): ReceivedStatement
    {
        $account ??= $this->account;
        $from = new \DateTimeImmutable($month.'-01');
        $parsed = array_map(static fn (string $amount): ParsedMovement => new ParsedMovement(
            str_starts_with($amount, '-') ? MovementDirection::Outgoing : MovementDirection::Incoming,
            Money::of(ltrim($amount, '-'), Currency::of('CZK')),
            $account->iban(),
            null,
            null,
            null,
            $from,
            null,
            null,
            null,
            null,
            null,
        ), $entries);
        $money = static fn (?string $amount): ?Money => null === $amount ? null : Money::of($amount, Currency::of('CZK'));

        return new ReceivedStatement(
            $account,
            new BankStatement(
                $account->iban(),
                Currency::of('CZK'),
                $parsed,
                sequence: null === $number ? null : StatementSequence::withinYear($number, $year ?? (int) $from->format('Y')),
                from: $from,
                to: $from->modify('last day of this month'),
                openingBalance: $money($opening),
                closingBalance: $money($closing),
            ),
            MovementProvenance::unverified(MovementChannel::Statement, $source),
            null,
            null,
            new \DateTimeImmutable('2026-12-01 10:00:00'),
        );
    }

    /**
     * @param list<StatementGap> $gaps
     *
     * @return list<array{StatementGapKind, ReceivedStatement, ?ReceivedStatement}>
     */
    private function described(array $gaps): array
    {
        return array_map(static fn (StatementGap $gap): array => [$gap->kind, $gap->statement, $gap->previous], $gaps);
    }
}
