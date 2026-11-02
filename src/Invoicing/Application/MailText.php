<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * Fills the tokens of a template in one pass, so a value that holds a token is
 * never expanded again. A token without a value stays as written: {link} is
 * filled only when the mail leaves, from a token never stored with the text.
 */
final readonly class MailText
{
    public const string TOKEN = '/\{([A-Za-z0-9_]+)\}/';

    /**
     * @param array<string, string> $values
     * @param bool                  $singleLine for a subject: a line break there
     *                                          could start a header of its own
     */
    public function render(string $template, array $values, bool $singleLine): string
    {
        $text = (string) preg_replace_callback(self::TOKEN, static fn (array $token): string => $values[$token[1]] ?? $token[0], $template);

        if (!$singleLine) {
            return str_replace(["\r\n", "\r"], "\n", $text);
        }

        return trim((string) preg_replace('/[\x00-\x1F\x7F\x{85}\x{2028}\x{2029}]+/u', ' ', $text));
    }
}
