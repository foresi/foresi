<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\SendPurpose;

/**
 * What the send dialog asks for: the addresses as typed, and the subject and body
 * as shown, already filled in. {link} marks where the link goes, and it is the
 * only token: any other brace is text. A reminder names which one of the invoice
 * it is, so the same step is never queued twice. Work without a signed-in person,
 * such as the daily run of recurring invoices, names whom it sends for.
 */
final readonly class SendRequest
{
    /**
     * @param list<string> $to
     * @param list<string> $cc
     * @param bool         $copyToMe     a copy with a link of its own to the firm's contact
     *                                   address, or to the signed-in user's when it has none
     * @param int|null     $reminderStep for a reminder, which one of the invoice, from 1
     * @param string|null  $onBehalfOf   the user a send of the system is made for, whose address
     *                                   takes the copy when the firm has no contact address; the
     *                                   send itself stays the system's, in the log and the budgets
     */
    public function __construct(
        public string $invoiceId,
        public array $to,
        public array $cc,
        public bool $copyToMe,
        public string $subject,
        public string $body,
        public SendPurpose $purpose = SendPurpose::Invoice,
        public ?int $reminderStep = null,
        public ?string $onBehalfOf = null,
    ) {
    }
}
