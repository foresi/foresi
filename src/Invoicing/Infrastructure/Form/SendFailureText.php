<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Form;

use App\Invoicing\Application\MailRefused;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Why a send failed, in the user's language: the stored reason code, with the
 * SMTP code when the server gave one. A row from before codes, or a code this
 * version does not know, shows the English text it stored.
 */
final readonly class SendFailureText
{
    public const string PREFIX = 'invoice.send_failure.';

    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function of(?string $code, ?int $smtp, ?string $stored): ?string
    {
        if (null === $code) {
            return $stored;
        }
        $key = self::PREFIX.$code;
        $parameters = ['smtp' => null === $smtp ? 'none' : (string) $smtp];
        if (MailRefused::RECIPIENT_REFUSED === $code) {
            // The refused address is kept only in the stored reason, between angle brackets.
            if (1 !== preg_match('/<([^<>]+)>/', $stored ?? '', $recipient)) {
                return $stored;
            }
            $parameters['recipient'] = $recipient[1];
        }
        $text = $this->translator->trans($key, $parameters);

        // A missing translation comes back as its key.
        return $key === $text ? $stored : $text;
    }
}
