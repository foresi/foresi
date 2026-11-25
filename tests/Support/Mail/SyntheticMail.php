<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Support\Mail;

/**
 * Synthetic raw mail for tests of the mail intake. Nothing is a real message:
 * example.com names only.
 */
final readonly class SyntheticMail
{
    public const string MESSAGE_ID = 'notice-1@mail.example.com';

    /**
     * A plain notification e-mail.
     *
     * @param array<string, string> $headers more header fields, placed first as a receiving server would
     */
    public static function plain(string $to, ?string $messageId = self::MESSAGE_ID, array $headers = [], string $body = "A payment arrived on your account.\r\n"): string
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name.': '.$value;
        }
        $lines[] = 'From: Example Bank <info@bank.example.com>';
        $lines[] = 'To: '.$to;
        $lines[] = 'Subject: Payment notification';
        $lines[] = 'Date: Fri, 20 Nov 2026 10:00:00 +0100';
        if (null !== $messageId) {
            $lines[] = 'Message-ID: <'.$messageId.'>';
        }
        $lines[] = 'MIME-Version: 1.0';
        $lines[] = 'Content-Type: text/plain; charset=utf-8';

        return implode("\r\n", $lines)."\r\n\r\n".$body;
    }
}
