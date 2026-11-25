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
 * A bank statement of an account does not follow on the statement before it: a
 * statement is missing or a balance differs, or it does not add up itself. The gap is named by the statement after it and the one before it, which an
 * inner mismatch has none of.
 */
#[DomainEvent('banking.statement.gap_detected', version: 1)]
final readonly class StatementGapDetected implements DomainEventPayload
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
