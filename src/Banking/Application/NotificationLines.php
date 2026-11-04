<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * The visible lines of a notification body, the common ground of every bank's
 * parser: a label and its value read the same whether the bank laid them out in
 * table cells, paragraphs, divs or plain text.
 *
 * Block elements, table cells and line breaks end a line. No-break and narrow
 * no-break spaces count as spaces, white space collapses and empty lines drop.
 * Scripts, styles and the document head are not visible and give nothing.
 */
final class NotificationLines
{
    private const array BREAKING = [
        'address', 'article', 'aside', 'blockquote', 'br', 'caption', 'center', 'dd', 'div', 'dl', 'dt',
        'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header',
        'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'table', 'tbody', 'td', 'tfoot', 'th',
        'thead', 'tr', 'ul',
    ];

    private const array INVISIBLE = ['head', 'noscript', 'script', 'style', 'template', 'title'];

    /**
     * @return list<string>
     */
    public static function of(string $htmlOrText, bool $isHtml): array
    {
        $text = $isHtml ? self::textOf($htmlOrText) : $htmlOrText;

        $lines = [];
        $split = preg_split('/\r\n|\r|\n/', $text);
        foreach (false === $split ? [] : $split as $line) {
            $line = trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $line));
            if ('' !== $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private static function textOf(string $html): string
    {
        if ('' === trim($html)) {
            return '';
        }

        // Every character beyond ASCII goes in as a numeric reference, so a charset the
        // document declares cannot make libxml decode the UTF-8 body a second time.
        $ascii = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML($ascii, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            return '';
        }

        return self::collect($document);
    }

    private static function collect(\DOMNode $node): string
    {
        if ($node instanceof \DOMText) {
            // Source line breaks inside a text run are white space in HTML, not line ends.
            return str_replace(["\r", "\n"], ' ', $node->data);
        }
        $name = $node instanceof \DOMElement ? strtolower($node->tagName) : '';
        if (\in_array($name, self::INVISIBLE, true) || $node instanceof \DOMComment) {
            return '';
        }

        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= self::collect($child);
        }

        return \in_array($name, self::BREAKING, true) ? "\n".$text."\n" : $text;
    }
}
