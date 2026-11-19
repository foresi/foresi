<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

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

    /**
     * A body, which is Markdown: each value goes in as text, its markup escaped
     * (MailMarkdown), so a customer's name cannot format the mail or add a link.
     *
     * @param array<string, string> $values
     */
    public function renderBody(string $template, array $values): string
    {
        $template = str_replace(["\r\n", "\r"], "\n", $template);
        $text = (string) preg_replace_callback(
            self::TOKEN,
            static function (array $token) use ($values, $template): string {
                [$whole, $at] = $token[0];
                if (!isset($values[$token[1][0]])) {
                    return $whole;
                }
                $before = substr($template, 0, $at);
                // Only blanks between the start of its line and the token.
                $atLineStart = '' === trim(substr($before, (int) strrpos($before, "\n")));

                return MailMarkdown::escape(str_replace(["\r\n", "\r"], "\n", $values[$token[1][0]]), $atLineStart);
            },
            $template,
            flags: \PREG_OFFSET_CAPTURE,
        );

        return $text;
    }
}
