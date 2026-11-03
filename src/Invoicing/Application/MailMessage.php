<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * One finished message. Every value that becomes a header is refused when it
 * holds a line break, so no value can add a header such as Bcc; the bodies may
 * break lines.
 */
final readonly class MailMessage
{
    /**
     * @param list<string> $to
     * @param list<string> $cc
     * @param string       $messageId "<id@domain>", the same on every attempt, so a repeat is recognisable
     */
    public function __construct(
        public string $from,
        public string $fromName,
        public ?string $replyTo,
        public array $to,
        public array $cc,
        public string $subject,
        public string $text,
        public string $html,
        public string $messageId,
    ) {
        if ([] === $to) {
            throw new \InvalidArgumentException('A message needs a recipient.');
        }
        foreach ([$from, $fromName, $replyTo ?? '', $subject, $messageId, ...$to, ...$cc] as $header) {
            if (1 === preg_match('/[\x00-\x1F\x7F]/', $header)) {
                throw new \InvalidArgumentException('A header value cannot hold a line break or a control character.');
            }
        }
        if (1 !== preg_match('/^<[^<>@\s]+@[^<>@\s]+>$/', $messageId)) {
            throw new \InvalidArgumentException('A message id is written as <id@domain>.');
        }
    }
}
