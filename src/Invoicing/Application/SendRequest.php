<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * What the send dialog asks for: the addresses as typed, and the subject and body
 * as shown, already filled in. {link} marks where the link goes, and it is the
 * only token: any other brace is text.
 */
final readonly class SendRequest
{
    /**
     * @param list<string> $to
     * @param list<string> $cc
     * @param bool         $copyToMe a copy with a link of its own to the firm's contact
     *                               address, or to the signed-in user's when it has none
     */
    public function __construct(
        public string $invoiceId,
        public array $to,
        public array $cc,
        public bool $copyToMe,
        public string $subject,
        public string $body,
    ) {
    }
}
