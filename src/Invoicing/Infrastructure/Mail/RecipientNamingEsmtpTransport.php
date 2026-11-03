<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Mail;

use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * Symfony's ESMTP transport that says which recipient the server refused. One
 * refused recipient, such as an unknown Cc, ends the whole message: Symfony
 * sends the envelope's recipients one by one and stops at the first refusal.
 */
final class RecipientNamingEsmtpTransport extends EsmtpTransport
{
    private const string RCPT = '/^RCPT TO:<([^<>\r\n]+)>/';

    /**
     * @param int[] $codes
     */
    public function executeCommand(string $command, array $codes): string
    {
        try {
            return parent::executeCommand($command, $codes);
        } catch (UnexpectedResponseException $refused) {
            if (!$refused instanceof RecipientRefused && 1 === preg_match(self::RCPT, $command, $recipient)) {
                throw new RecipientRefused($recipient[1], $refused);
            }

            throw $refused;
        }
    }
}
