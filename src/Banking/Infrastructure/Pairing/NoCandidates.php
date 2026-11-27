<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Infrastructure\Pairing;

use Foresi\Banking\Application\Candidates;
use Foresi\Shared\Domain\Currency;

/**
 * No document waits for a payment when no module that keeps them, such as
 * Invoicing, is installed.
 */
final readonly class NoCandidates implements Candidates
{
    public function withSymbol(string $variableSymbol): array
    {
        return [];
    }

    public function open(Currency $currency, string $text, array $ids, int $limit): array
    {
        return [];
    }

    public function refunds(?string $variableSymbol): array
    {
        return [];
    }

    public function paidFrom(?string $counterIban, ?string $counterAccount): array
    {
        return [];
    }
}
