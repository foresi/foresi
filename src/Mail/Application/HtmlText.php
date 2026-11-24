<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Application;

/**
 * The visible text of an HTML body, a line per block: block elements, table
 * cells and line breaks end a line, white space collapses and empty lines drop.
 * Scripts, styles and the document head give nothing. Nothing of the markup is
 * followed or fetched.
 */
final class HtmlText
{
    private const array BREAKING = [
        'address', 'article', 'aside', 'blockquote', 'br', 'caption', 'center', 'dd', 'div', 'dl', 'dt',
        'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header',
        'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'table', 'tbody', 'td', 'tfoot', 'th',
        'thead', 'tr', 'ul',
    ];

    private const array INVISIBLE = ['head', 'noscript', 'script', 'style', 'template', 'title'];

    public static function of(string $html): string
    {
        if ('' === $html) {
            return '';
        }
        // Every character beyond ASCII goes in as a numeric reference, so a charset the
        // document declares cannot make libxml decode the UTF-8 body a second time.
        $ascii = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML($ascii, \LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            return '';
        }

        $lines = [];
        $split = preg_split('/\r\n|\r|\n/', self::collect($document));
        foreach (false === $split ? [] : $split as $line) {
            $line = trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $line));
            if ('' !== $line) {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    private static function collect(\DOMNode $node): string
    {
        if ($node instanceof \DOMText) {
            return str_replace(["\r", "\n"], ' ', $node->data);
        }
        $name = $node instanceof \DOMElement ? $node->tagName : '';
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
