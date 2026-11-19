<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

/**
 * A mail body is Markdown. A value filled into it, such as a customer's name,
 * is text: the characters that would start markup in it are escaped with a
 * backslash, so a name like "[Pay here](https://…)" reads as written and never
 * becomes a link or emphasis, and a bare web address stays text. A character that starts a block (a heading, a
 * quote, a list) is escaped only at the start of a line, where it would act,
 * so a negative amount or a date in a sentence stays as it reads.
 */
final class MailMarkdown
{
    /** What starts inline markup anywhere: emphasis, code, links, autolinks, strikethrough. */
    private const string INLINE = '/([\\\\`*_\[\]<>~])/';
    /** What starts a block at the start of a line: a heading, quote, list item, setext underline. */
    private const string BLOCK = '/^([ \t]*)([#>+=-]|\d{1,9}(?=[.)]))/';
    /** A backslash before ASCII punctuation, as CommonMark reads it. */
    private const string ESCAPED = '/\\\\([!-\/:-@\[-`{-~])/';

    /**
     * @param bool $atLineStart the value starts a line of the text it goes into
     */
    public static function escape(string $value, bool $atLineStart): string
    {
        $value = (string) preg_replace(self::INLINE, '\\\\$1', $value);
        $value = (string) preg_replace('/&(?=#?[A-Za-z0-9]+;)/', '\\\\&', $value);
        // A bare address would be linked as it stands (GitHub's autolinks).
        $value = (string) preg_replace(['~:(?=//)~', '~(?<![A-Za-z0-9])(www)\\.~i'], ['\\\\:', '$1\\\\.'], $value);
        $lines = explode("\n", $value);
        foreach ($lines as $index => $line) {
            if (0 === $index && !$atLineStart) {
                continue;
            }
            $lines[$index] = (string) preg_replace_callback(
                self::BLOCK,
                // A number before "." or ")" starts an ordered list: the mark after it is what is escaped.
                static fn (array $match): string => $match[1].(ctype_digit($match[2]) ? $match[2].'\\' : '\\'.$match[2]),
                $line,
            );
        }

        return implode("\n", $lines);
    }

    /**
     * The plain-text part of a mail reads the source without the backslashes
     * that only keep a character from acting as markup.
     */
    public static function plain(string $source): string
    {
        return (string) preg_replace(self::ESCAPED, '$1', $source);
    }
}
