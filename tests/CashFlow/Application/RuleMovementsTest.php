<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Application;

use Foresi\CashFlow\Application\RuleMovements;
use Foresi\CashFlow\Contract\ActualFlow;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\CashFlow\Contract\FoundMovement;
use Foresi\CashFlow\Contract\MovementRule;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\CashFlow\Support\FakeActualSource;
use Foresi\Tests\CashFlow\Support\SpyLogger;
use PHPUnit\Framework\TestCase;

/**
 * The bank movements that meet match rules, for other modules: each meets the
 * first rule it meets, a failing source gives nothing, and the amount never
 * decides.
 */
final class RuleMovementsTest extends TestCase
{
    public function testAMovementMeetsTheFirstMatchingRuleOnly(): void
    {
        $bank = new FakeActualSource('banking.movements', [
            $this->movement('m1', '2026-11-02', FlowDirection::Out, '123/0100', '42', 1_000),
            $this->movement('m2', '2026-11-03', FlowDirection::Out, '000000-0123/0100', '0042', 999_999),
            $this->movement('m3', '2026-11-04', FlowDirection::Out, '555/0800', '7', 1_000),
            new ActualFlow('invoicing.invoices', new FlowSubject('invoice', 'i1'), FlowDirection::Out, $this->czk(1_000), $this->day('2026-11-04'), counterpartyId: 'c1', counterpartyAccount: '123/0100', variableSymbol: '42'),
        ]);
        $rules = [
            new MovementRule('nothing', null, null),
            new MovementRule('first', '123/0100', '42'),
            new MovementRule('second', '123/0100', null),
            new MovementRule('symbol', null, '7'),
        ];

        $found = new RuleMovements([$bank])->find($this->day('2026-11-01'), $this->day('2026-11-30'), $rules);

        self::assertSame([['first', 'm1'], ['first', 'm2'], ['symbol', 'm3']], $this->keys($found->movements), 'A padded account and symbol still meet; another amount does not stop a match.');
        self::assertSame([], $found->unavailable);
        self::assertSame([['2026-11-01', '2026-11-30']], $bank->asked);
        $first = $found->movements[0];
        self::assertSame(['2026-11-02', FlowDirection::Out], [$first->on->format('Y-m-d'), $first->direction]);
        self::assertTrue($this->czk(1_000)->equals($first->amount));
    }

    public function testADirectionRuleSkipsTheOtherDirection(): void
    {
        $bank = new FakeActualSource('banking.movements', [
            $this->movement('out', '2026-11-02', FlowDirection::Out, '123/0100', '42', 1_000),
            $this->movement('in', '2026-11-03', FlowDirection::In, '123/0100', '42', 1_000),
        ]);
        $rules = [
            new MovementRule('refund', '123/0100', '42', FlowDirection::In),
            new MovementRule('any', '123/0100', '42'),
        ];

        $found = new RuleMovements([$bank])->find($this->day('2026-11-01'), $this->day('2026-11-30'), $rules);

        self::assertSame([['any', 'out'], ['refund', 'in']], $this->keys($found->movements));
        self::assertSame(FlowDirection::In, $found->movements[1]->direction);
    }

    public function testAFailingSourceIsListedAndGivesNothing(): void
    {
        $logger = new SpyLogger();
        $broken = new FakeActualSource('banking.broken', [
            $this->movement('b1', '2026-11-02', FlowDirection::Out, '123/0100', '42', 1_000),
            $this->movement('b2', '2026-11-03', FlowDirection::Out, '123/0100', '42', 1_000),
        ], fails: true);
        $other = new FakeActualSource('banking.other', [$this->movement('o1', '2026-11-02', FlowDirection::Out, '123/0100', '42', 1_000)], fails: true);
        $bank = new FakeActualSource('banking.movements', [$this->movement('m1', '2026-11-04', FlowDirection::Out, '123/0100', '42', 1_000)]);

        $found = new RuleMovements([$broken, $broken, $bank, $other], $logger)->find($this->day('2026-11-01'), $this->day('2026-11-30'), [new MovementRule('levy', '123/0100', '42')]);

        self::assertSame([['levy', 'm1']], $this->keys($found->movements), 'What the broken source gave before failing is dropped.');
        self::assertSame(['banking.broken', 'banking.other'], $found->unavailable, 'Each source once.');
        self::assertCount(3, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('banking.broken', $logger->records[0]['context']['source'] ?? null);
        self::assertInstanceOf(\RuntimeException::class, $logger->records[0]['context']['exception'] ?? null);
    }

    public function testMovementsComeOldestFirst(): void
    {
        $later = new FakeActualSource('banking.later', [
            $this->movement('b', '2026-11-05', FlowDirection::Out, '123/0100', '42', 1_000),
            $this->movement('z', '2026-11-03', FlowDirection::Out, '123/0100', '42', 1_000),
        ]);
        $earlier = new FakeActualSource('banking.earlier', [
            $this->movement('a', '2026-11-05', FlowDirection::Out, '123/0100', '42', 1_000),
            new ActualFlow('banking.earlier', new FlowSubject('bank_movement', 'y'), FlowDirection::Out, $this->czk(1_000), new \DateTimeImmutable('2026-11-03 23:00'), counterpartyAccount: '123/0100', variableSymbol: '42'),
        ]);

        $found = new RuleMovements([$later, $earlier])->find($this->day('2026-11-01'), $this->day('2026-11-30'), [new MovementRule('levy', '123/0100', '42')]);

        self::assertSame(['y', 'z', 'a', 'b'], array_map(static fn (FoundMovement $movement): string => $movement->id, $found->movements), 'By day, then by id.');
    }

    public function testNoRuleAsksNoSource(): void
    {
        $bank = new FakeActualSource('banking.movements', [$this->movement('m1', '2026-11-02', FlowDirection::Out, '123/0100', '42', 1_000)]);

        $found = new RuleMovements([$bank])->find($this->day('2026-11-01'), $this->day('2026-11-30'), []);

        self::assertSame([[], []], [$found->movements, $found->unavailable]);
        self::assertSame([], $bank->asked);
    }

    private function movement(string $id, string $on, FlowDirection $direction, string $account, string $symbol, int $czk): ActualFlow
    {
        return new ActualFlow('banking.movements', new FlowSubject('bank_movement', $id), $direction, $this->czk($czk), $this->day($on), counterpartyAccount: $account, variableSymbol: $symbol);
    }

    /**
     * @param list<FoundMovement> $movements
     *
     * @return list<array{string, string}>
     */
    private function keys(array $movements): array
    {
        return array_map(static fn (FoundMovement $movement): array => [$movement->ruleKey, $movement->id], $movements);
    }

    private function czk(int $units): Money
    {
        return Money::ofMinor($units * 100, Currency::of('CZK'));
    }

    private function day(string $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day);
    }
}
