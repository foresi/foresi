<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Shared\Domain\Currency;

/**
 * The open invoices a movement may pay, read for the matcher.
 */
interface Candidates
{
    /**
     * The invoices of the firm in the active tenant that are not in the trash, have
     * something left to pay and carry the variable symbol, leading zeros aside on
     * both sides. Every currency: the matcher tells a currency mismatch from no
     * invoice at all.
     *
     * @param string $variableSymbol digits without leading zeros, not empty
     *
     * @return list<Candidate> oldest due first
     */
    public function withSymbol(string $firmId, string $variableSymbol): array;

    /**
     * The invoices of the firm in the active tenant in the currency that are not in
     * the trash and have something left to pay, oldest due first: those the text
     * finds in the number, the customer's name or the variable symbol, or all of
     * them for no text, and the ones named by id whatever the text.
     *
     * @param list<string> $ids
     *
     * @return list<Candidate>
     */
    public function open(string $firmId, Currency $currency, string $text, array $ids, int $limit): array;
}
