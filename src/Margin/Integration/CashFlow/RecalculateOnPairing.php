<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Integration\CashFlow;

use Foresi\CashFlow\Contract\Event\DocumentPairingChanged;
use Foresi\Margin\Application\CostShareCalculation;
use Foresi\Shared\Contract\Event\DomainEventListener;
use Foresi\Shared\Contract\Event\RecordedEvent;

/**
 * Counts the shares of a received document again when its pairing with a
 * planned occurrence changes, with every document of the occurrence it is
 * paired with now and of the one it was paired with before (found from its
 * shares), since a fixed mapping is filled per occurrence.
 *
 * An asynchronous listener of Cash flow's event (ADR 0030), run after the
 * pairing is committed, so it reads the stored pairings. It recalculates from
 * the state as it is, not from the facts of the event, so a delivery that
 * comes twice or out of order still leaves the right shares. The class name
 * is the listener's key; keep it.
 */
final readonly class RecalculateOnPairing implements DomainEventListener
{
    public const string EVENT = 'cash-flow.document_pairing.changed';

    public function __construct(private CostShareCalculation $calculation)
    {
    }

    public function listensTo(): string
    {
        return self::EVENT;
    }

    public function __invoke(RecordedEvent $event): void
    {
        $payload = $event->payload;
        if (!$payload instanceof DocumentPairingChanged || CashFlowPairings::RECEIVED_DOCUMENT !== $payload->subjectType) {
            return;
        }
        // The relay runs each delivery in a transaction of its own and flushes it (DeliveryWork).
        $this->calculation->recalculate($payload->subjectId);
    }
}
