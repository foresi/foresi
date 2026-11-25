<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Mail;

/**
 * The mail a received document was filed from, as its page and the API show it.
 * The sender and the subject are the sender's own text with every inbound
 * address cut out: show them escaped, never follow them.
 */
final readonly class MailSource
{
    /**
     * @param string $verdict the sender check of Mail on arrival: verified, failed or unknown
     */
    public function __construct(
        public string $from,
        public string $subject,
        public ?\DateTimeImmutable $sentAt,
        public string $verdict,
    ) {
    }
}
