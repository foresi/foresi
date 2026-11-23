<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Integration\Margin;

use Foresi\Margin\Contract\CostSharesRecalculation;
use Foresi\Purchasing\Contract\Event\ReceivedDocumentChanged;
use Foresi\Purchasing\Contract\Event\ReceivedDocumentRecorded;
use Foresi\Purchasing\Contract\Event\ReceivedDocumentRestored;
use Foresi\Purchasing\Contract\Event\ReceivedDocumentTrashed;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Counts the shares of a received document again when it is recorded,
 * changed, trashed or restored. A synchronous listener of Purchasing's own
 * events (ADR 0030), so the shares are committed or rolled back with the
 * change. It lives in Purchasing's integration, as Margin does not depend on
 * Purchasing's events (ADR 0033).
 */
final readonly class RecalculateOnDocumentEvents
{
    public function __construct(private CostSharesRecalculation $calculation)
    {
    }

    #[AsEventListener]
    public function onRecorded(ReceivedDocumentRecorded $event): void
    {
        $this->calculation->recalculate($event->receivedDocumentId);
    }

    #[AsEventListener]
    public function onChanged(ReceivedDocumentChanged $event): void
    {
        $this->calculation->recalculate($event->receivedDocumentId);
    }

    #[AsEventListener]
    public function onTrashed(ReceivedDocumentTrashed $event): void
    {
        $this->calculation->recalculate($event->receivedDocumentId);
    }

    #[AsEventListener]
    public function onRestored(ReceivedDocumentRestored $event): void
    {
        $this->calculation->recalculate($event->receivedDocumentId);
    }
}
