<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

use Foresi\Inbox\Domain\ForwardedSender;

/**
 * Finds who wrote a forwarded mail, from the forward block the mail program of
 * the forwarder put above the original, in English or Czech:
 *
 * - Gmail: "---------- Forwarded message ---------" / "---------- Přeposlaná zpráva ---------";
 * - Outlook on the desktop: "-----Original Message-----" / "-----Původní zpráva-----", or a line of
 *   underscores, as Outlook on the web and newer desktop versions write it;
 * - Apple Mail: "Begin forwarded message:" / "Začátek přeposílané zprávy:".
 *
 * The block's From ("Od") line follows within a few header lines; it names the
 * sender as "Name <a@example.com>", "Name [mailto:a@example.com]" or a bare
 * address. The first block from the top counts: in a forward of a forward it is
 * the last hop. What it says is the forwarder's text, never verified.
 */
final class ForwardParser
{
    /** The lines that open a forward block. */
    private const array MARKERS = [
        '/^-{4,}\s*(?:forwarded message|přeposlaná zpráva)\s*-{4,}$/iu',
        '/^-{3,}\s*(?:original message|původní zpráva)\s*-{3,}$/iu',
        '/^_{10,}$/',
        '/^(?:begin forwarded message|začátek přeposílané zprávy)\s*:$/iu',
    ];

    /** The From line of the block: "From:" or "Od:", bold in some plain text renderings. */
    private const string FROM = '/^\*?(?:from|od)\s*:\*?\s*(.+)$/iu';

    /** A header line of the block, such as "Date:", "Odesláno:" or "Subject:". */
    private const string HEADER = '/^\*?[\p{L}][\p{L} -]{0,30}:\*?(?:\s|$)/u';

    private const string ADDRESS = '/[^\s<>\[\]()"\',;:]+@[^\s<>\[\]()"\',;:]+\.[^\s<>\[\]()"\',;:]+/u';

    /** How many header lines after the marker are searched for the From line. */
    private const int HEADER_LINES = 8;

    public function parse(?string $text): ?ForwardedSender
    {
        if (null === $text || '' === $text) {
            return null;
        }
        $lines = preg_split('/\R/u', $text);
        if (false === $lines) {
            return null;
        }
        foreach ($lines as $index => $line) {
            if ($this->isMarker($this->unquoted($line))) {
                $sender = $this->senderAfter($lines, $index + 1);
                if ($sender instanceof ForwardedSender) {
                    return $sender;
                }
            }
        }

        return null;
    }

    private function isMarker(string $line): bool
    {
        return array_any(self::MARKERS, static fn (string $marker): bool => 1 === preg_match($marker, $line));
    }

    /**
     * @param list<string> $lines
     */
    private function senderAfter(array $lines, int $start): ?ForwardedSender
    {
        $headers = 0;
        for ($index = $start, $count = \count($lines); $index < $count && $headers < self::HEADER_LINES; ++$index) {
            $line = $this->unquoted($lines[$index]);
            if ('' === $line && 0 === $headers) {
                // Apple Mail leaves a blank line between the marker and the block; a later one ends the headers.
                continue;
            }
            if (1 === preg_match(self::FROM, $line, $from)) {
                return $this->sender($from[1]);
            }
            if (1 !== preg_match(self::HEADER, $line)) {
                return null;
            }
            ++$headers;
        }

        return null;
    }

    private function sender(string $value): ?ForwardedSender
    {
        if (1 !== preg_match(self::ADDRESS, $value, $found)) {
            return null;
        }
        $address = rtrim($found[0], '.');
        if (\strlen($address) > ForwardedSender::MAX_ADDRESS) {
            return null;
        }
        $name = str_replace($address, '', $value);
        $name = trim(preg_replace('/\[\s*mailto:\s*\]|<\s*>|\(\s*\)/iu', '', $name) ?? '', " \t\"'<>[]");

        return new ForwardedSender($address, '' === $name ? null : $name);
    }

    /** The line without its quoting ("> ") and surrounding white space. */
    private function unquoted(string $line): string
    {
        return trim(preg_replace('/^(?:\s*>)+/u', '', $line) ?? $line);
    }
}
