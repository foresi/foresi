<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Mail;

use Foresi\Purchasing\Domain\MailIntakeOutcome;

/**
 * One mail of the signed-in person's capture address and what came of it, as
 * the settings list it. The sender and the subject are the sender's own text
 * with every inbound address cut out, null once Mail no longer holds the mail:
 * show them escaped. The names of the attachments left out are the sender's
 * text too.
 */
final readonly class RecentMail
{
    /**
     * @param list<array{name: string, reason: string}> $skipped
     */
    public function __construct(
        public string $messageId,
        public \DateTimeImmutable $receivedAt,
        public ?string $from,
        public ?string $subject,
        public MailIntakeOutcome $outcome,
        public int $drafts,
        public array $skipped,
        public int $skippedCount,
        public ?string $firstDraftId,
    ) {
    }
}
