<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Party\Domain\Party;
use Psr\Clock\ClockInterface;

/**
 * Archives and restores parties. Archived parties keep all their data and
 * stay referenceable; only the lists hide them. Both are repeatable.
 */
final readonly class ArchiveParty
{
    public function __construct(private TransactionalWork $transaction, private ClockInterface $clock)
    {
    }

    /**
     * @param list<Party> $parties
     */
    public function archive(array $parties): void
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $this->transaction->run(static function () use ($parties, $now): void {
            foreach ($parties as $party) {
                $party->archive($now);
            }
        });
    }

    /**
     * @param list<Party> $parties
     */
    public function restore(array $parties): void
    {
        $this->transaction->run(static function () use ($parties): void {
            foreach ($parties as $party) {
                $party->restore();
            }
        });
    }
}
