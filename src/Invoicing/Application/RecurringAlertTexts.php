<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Party\Domain\DocumentLanguage;

/**
 * Port: the subject and text of a mail about recurring templates a run could not
 * issue or send, in a document language, with a link to the recurring invoices
 * in the app. One template gets a mail of its own; several, a list of them.
 */
interface RecurringAlertTexts
{
    /**
     * @param non-empty-list<array{customer: string, code: string}> $failures each template told, with its customer and code:
     *                                                                        a problem key of IssueRefused, or "send." and a
     *                                                                        reason of SendRefused
     * @param \DateTimeImmutable                                    $today    the tenant's day of the run
     *
     * @return array{subject: string, text: string}
     */
    public function compose(string $firm, DocumentLanguage $language, array $failures, \DateTimeImmutable $today): array;
}
