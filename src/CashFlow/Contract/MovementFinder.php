<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

/**
 * The bank movements that meet match rules, for another module (ADR 0033),
 * such as Taxes and contributions finding what paid a levy. A movement meets a
 * rule by its counterparty account and variable symbol, as a planned item's
 * match rule does (ActualFlow::meets); the amount never decides a match, it is
 * the caller's to compare.
 */
interface MovementFinder
{
    /**
     * The movements booked between the two days, both included, each with the
     * first rule in the given order that it meets, and only that one. An actual
     * source that fails is logged and listed as unavailable, and nothing it gave
     * is kept.
     *
     * @param list<MovementRule> $rules
     */
    public function find(\DateTimeImmutable $from, \DateTimeImmutable $to, array $rules): FoundMovements;
}
