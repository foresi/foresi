<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Party\Contract\Event\ContactArchived;
use Foresi\Party\Contract\Event\ContactKind;
use Foresi\Party\Contract\Event\ContactRestored;
use Foresi\Party\Domain\Party;
use Foresi\Shared\Contract\Event\EventRecorder;
use Psr\Clock\ClockInterface;

/**
 * Archives and restores parties. Archived parties keep all their data and
 * stay referenceable; only the lists hide them. Both are repeatable; only a
 * party that changes records party.contact.archived or .restored. Only the
 * owners and members of the organisation do it, or the application's own work.
 */
final readonly class ArchiveParty
{
    public function __construct(
        private TransactionalWork $transaction,
        private ClockInterface $clock,
        private PartyAccess $access,
        private EventRecorder $events,
    ) {
    }

    /**
     * @param list<Party> $parties
     *
     * @throws PartyNotAllowed for a guest
     */
    public function archive(array $parties): void
    {
        $this->access->denyUnlessMayChange();
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $this->transaction->run(function () use ($parties, $now): void {
            foreach ($parties as $party) {
                if (!$party->isArchived()) {
                    $party->archive($now);
                    $this->events->record(new ContactArchived($party->id(), ContactKind::from($party->kind()->value)));
                }
            }
        });
    }

    /**
     * @param list<Party> $parties
     *
     * @throws PartyNotAllowed for a guest
     */
    public function restore(array $parties): void
    {
        $this->access->denyUnlessMayChange();
        $this->transaction->run(function () use ($parties): void {
            foreach ($parties as $party) {
                if ($party->isArchived()) {
                    $party->restore();
                    $this->events->record(new ContactRestored($party->id(), ContactKind::from($party->kind()->value)));
                }
            }
        });
    }
}
