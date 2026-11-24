<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Integration\CashFlow;

use Foresi\CashFlow\Contract\Event\DocumentPairingChanged;
use Foresi\Margin\Application\CostPairing;
use Foresi\Margin\Application\CostShareCalculation;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Counts the shares of a received document again when its pairing with a
 * planned occurrence changes, with every document of the occurrence it is
 * paired with now and of the one it was paired with before (found from its
 * shares), since a fixed mapping is filled per occurrence. A synchronous
 * listener (ADR 0030), so the shares are committed or rolled back with the
 * pairing. Only auto and confirmed pairings carry a mapping; a proposed or
 * declined one is no pairing for the margin.
 */
final readonly class RecalculateOnPairing
{
    private const array COUNTED = ['auto', 'confirmed'];

    public function __construct(private CashFlowPairings $pairings, private CostShareCalculation $calculation)
    {
    }

    #[AsEventListener]
    public function onPairingChanged(DocumentPairingChanged $event): void
    {
        if (CashFlowPairings::RECEIVED_DOCUMENT !== $event->subjectType) {
            return;
        }
        $now = \in_array($event->state, self::COUNTED, true) && null !== $event->plannedItemId && null !== $event->occurrenceOn
            ? new CostPairing($event->plannedItemId, new \DateTimeImmutable(substr($event->occurrenceOn, 0, 10)))
            : null;
        $this->pairings->remember($event->subjectId, $now);
        $this->calculation->recalculate($event->subjectId);
    }
}
