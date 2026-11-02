<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Form;

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
        $text = $this->translator->trans($key, ['smtp' => null === $smtp ? 'none' : (string) $smtp]);

        // A missing translation comes back as its key.
        return $key === $text ? $stored : $text;
    }
}
