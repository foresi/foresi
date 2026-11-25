<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application;

use Foresi\Banking\Contract\StatementGapKind;
use Foresi\Banking\Domain\ReceivedStatement;

/**
 * Whether the statements of an account follow on, read from them each time and
 * never stored, as Odoo checks a statement's opening balance against the closing
 * balance of the one before ("…or an earlier statement is missing"), and camt.053
 * and MT940 number their statements.
 *
 * The statements of one account and one source are a chain, ordered by the end of
 * their period, then their number, then when they came: two sources of one account
 * (a monthly statement file and a daily API, say) cover the days differently, so
 * only the statements of one source are neighbours. Between two neighbours:
 *
 * - a number gap when both carry a number of one kind and the later is not the
 *   next one (a number within a year starts at 1 in a new year);
 * - otherwise a balance gap when the later opening balance is not the earlier
 *   closing balance.
 *
 * A statement of the number of the one before is that statement again, as the bank
 * corrected it: it takes its place in the chain.
 *
 * Inside a statement, an inner mismatch when its opening balance and its entries do
 * not add up to its closing balance, as a cut-off file would. A statement without a
 * number or a balance, the first statement of a chain and a bank mail are never a
 * gap.
 */
final class StatementChain
{
    private function __construct()
    {
    }

    /**
     * @param list<ReceivedStatement> $statements any statements, of any accounts and sources
     *
     * @return list<StatementGap> chain by chain, in the order of each chain
     */
    public static function gaps(array $statements): array
    {
        $chained = [];
        foreach ($statements as $statement) {
            if (null === $statement->sequence() && null === $statement->openingBalance() && null === $statement->closingBalance()) {
                continue;
            }
            $chained[] = $statement;
        }
        $gaps = [];
        foreach (self::grouped($chained) as $chain) {
            $before = null;
            $previous = null;
            foreach (self::ordered($chain) as $statement) {
                // A statement of the same number again, corrected by the bank, takes the place of the one before.
                if ($previous instanceof ReceivedStatement && self::reissues($statement, $previous)) {
                    $previous = $before;
                } else {
                    $before = $previous;
                }
                $between = $previous instanceof ReceivedStatement ? self::between($previous, $statement) : null;
                if ($between instanceof StatementGap) {
                    $gaps[] = $between;
                }
                $inner = self::inner($statement);
                if ($inner instanceof StatementGap) {
                    $gaps[] = $inner;
                }
                $previous = $statement;
            }
        }

        return $gaps;
    }

    /**
     * The statements chain by chain: one chain per account and source, each in its
     * order, statements without a number or a balance too.
     *
     * @param list<ReceivedStatement> $statements any statements, of any accounts and sources
     *
     * @return list<list<ReceivedStatement>>
     */
    public static function chains(array $statements): array
    {
        return array_map(self::ordered(...), self::grouped($statements));
    }

    /**
     * How many accounts have a gap in a chain of theirs.
     *
     * @param list<ReceivedStatement> $statements any statements, of any accounts and sources
     */
    public static function accountsWithGaps(array $statements): int
    {
        return \count(array_unique(array_map(static fn (StatementGap $gap): string => $gap->statement->account()->id(), self::gaps($statements))));
    }

    /**
     * @param list<ReceivedStatement> $chain
     *
     * @return list<ReceivedStatement>
     */
    public static function ordered(array $chain): array
    {
        usort($chain, static fn (ReceivedStatement $a, ReceivedStatement $b): int => [
            ($a->periodTo() ?? $a->receivedAt())->format('Y-m-d'),
            $a->sequence()?->year,
            $a->sequence()?->number,
            $a->receivedAt()->format('Y-m-d H:i:s.u'),
            $a->id(),
        ] <=> [
            ($b->periodTo() ?? $b->receivedAt())->format('Y-m-d'),
            $b->sequence()?->year,
            $b->sequence()?->number,
            $b->receivedAt()->format('Y-m-d H:i:s.u'),
            $b->id(),
        ]);

        return $chain;
    }

    /**
     * One group per account and source, in the order each first comes.
     *
     * @param list<ReceivedStatement> $statements
     *
     * @return list<list<ReceivedStatement>>
     */
    private static function grouped(array $statements): array
    {
        $byAccount = [];
        foreach ($statements as $statement) {
            $byAccount[$statement->account()->id()][$statement->sourceId()][] = $statement;
        }
        $groups = [];
        foreach ($byAccount as $bySource) {
            foreach ($bySource as $group) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    private static function reissues(ReceivedStatement $statement, ReceivedStatement $previous): bool
    {
        $number = $statement->sequence();
        $earlier = $previous->sequence();

        return $number instanceof \Foresi\Banking\Contract\StatementSequence && $earlier instanceof \Foresi\Banking\Contract\StatementSequence && $number->kind === $earlier->kind && $number->number === $earlier->number && $number->year === $earlier->year;
    }

    private static function between(ReceivedStatement $previous, ReceivedStatement $statement): ?StatementGap
    {
        $earlier = $previous->sequence();
        $later = $statement->sequence();
        if ($earlier instanceof \Foresi\Banking\Contract\StatementSequence && $later instanceof \Foresi\Banking\Contract\StatementSequence && false === $later->followsOn($earlier)) {
            return new StatementGap(StatementGapKind::Number, $statement, $previous);
        }
        $closing = $previous->closingBalance();
        $opening = $statement->openingBalance();
        if ($closing instanceof \Foresi\Shared\Domain\Money && $opening instanceof \Foresi\Shared\Domain\Money && !$closing->equals($opening)) {
            return new StatementGap(StatementGapKind::Balance, $statement, $previous, $closing, $opening);
        }

        return null;
    }

    private static function inner(ReceivedStatement $statement): ?StatementGap
    {
        $opening = $statement->openingBalance();
        $closing = $statement->closingBalance();
        if (!$opening instanceof \Foresi\Shared\Domain\Money || !$closing instanceof \Foresi\Shared\Domain\Money) {
            return null;
        }
        $expected = $opening->plus($statement->entriesNet());

        return $expected->equals($closing) ? null : new StatementGap(StatementGapKind::Inner, $statement, null, $expected, $closing);
    }
}
