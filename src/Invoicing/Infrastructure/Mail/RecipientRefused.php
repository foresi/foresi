<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Mail;

use Symfony\Component\Mailer\Exception\UnexpectedResponseException;

/**
 * The server refused one recipient of the envelope. The address is the one
 * Foresi sent in RCPT TO, never anything the server said.
 */
final class RecipientRefused extends UnexpectedResponseException
{
    public function __construct(public readonly string $recipient, UnexpectedResponseException $refused)
    {
        parent::__construct($refused->getMessage(), $refused->getCode(), $refused);
    }
}
