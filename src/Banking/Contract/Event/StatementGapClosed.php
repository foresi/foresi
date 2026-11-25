<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Contract\Event;

use Foresi\Banking\Contract\StatementGapKind;
use Foresi\Shared\Contract\Event\DomainEvent;
use Foresi\Shared\Contract\Event\DomainEventPayload;
use Foresi\Shared\Contract\Event\EventSubject;

/**
 * Two neighbouring bank statements of an account follow on again: a statement
 * that came later filled the gap between them. The gap is named as when it was
 * detected, by the statement after it and the one before it.
 */
#[DomainEvent('banking.statement.gap_closed', version: 1)]
final readonly class StatementGapClosed implements DomainEventPayload
{
    /**
     * @param string|null $previousStatementId the statement before the gap, or null for a statement that does not add up
     */
    public function __construct(
        public string $statementId,
        public ?string $previousStatementId,
        public string $firmId,
        public string $accountId,
        public StatementGapKind $kind,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('bank_statement', $this->statementId);
    }
}
