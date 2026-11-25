<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Mail;

use Foresi\Purchasing\Domain\MailIntake;

/**
 * The mails taken in by the capture addresses, one row each.
 */
interface MailIntakes
{
    public function add(MailIntake $intake): void;

    public function find(string $messageId): ?MailIntake;

    /**
     * The row read again with a lock until the transaction ends, so a second
     * run waits for the first; null when it is not there.
     */
    public function lock(string $messageId): ?MailIntake;

    /**
     * The sender check Mail made of the mail a draft was filed from.
     */
    public function verdictOf(string $messageId): ?string;

    /**
     * The newest mails of a person, newest first.
     *
     * @return list<MailIntake>
     */
    public function latestOf(string $ownerUserId, int $limit): array;

    /**
     * The first draft each mail made, by the id of the mail; mails without a draft are left out.
     *
     * @param list<string> $messageIds
     *
     * @return array<string, string>
     */
    public function firstDraftsOf(array $messageIds): array;
}
