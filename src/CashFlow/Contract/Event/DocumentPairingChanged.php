<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract\Event;

use Foresi\Shared\Contract\Event\DomainEvent;
use Foresi\Shared\Contract\Event\DomainEventPayload;
use Foresi\Shared\Contract\Event\EventSubject;

/**
 * The pairing of a document with a planned occurrence was created, changed or removed.
 */
#[DomainEvent('cash-flow.document_pairing.changed', version: 1)]
final readonly class DocumentPairingChanged implements DomainEventPayload
{
    /**
     * @param string|null $state                 auto, confirmed, proposed or declined; null when the pairing was removed
     * @param string|null $occurrenceOn          the day of the occurrence, ISO 8601
     * @param string|null $previousPlannedItemId the planned item before the change, when there was one
     */
    public function __construct(
        public string $subjectType,
        public string $subjectId,
        public ?string $state,
        public ?string $plannedItemId,
        public ?string $occurrenceOn,
        public ?string $previousPlannedItemId,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject($this->subjectType, $this->subjectId);
    }
}
