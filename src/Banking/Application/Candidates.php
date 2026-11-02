<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

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
}
