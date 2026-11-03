<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * The two parts of a mail that carries a link: plain text, and minimal HTML in
 * which everything the writer typed is escaped and only the link is markup. The
 * link goes where the body has {link}, or on a paragraph of its own at the end:
 * a link mail without its link would be worthless.
 */
final readonly class LinkedMailBody
{
    private const string LINK = '{'.MailVariables::LINK.'}';

    public function __construct(private string $body, private string $url)
    {
    }

    public function text(): string
    {
        if (str_contains($this->body, self::LINK)) {
            return str_replace(self::LINK, $this->url, $this->body);
        }

        return rtrim($this->body)."\n\n".$this->url;
    }

    public function html(): string
    {
        $body = str_contains($this->body, self::LINK) ? $this->body : rtrim($this->body)."\n\n".self::LINK;
        $anchor = '<a href="'.self::escape($this->url).'">'.self::escape($this->url).'</a>';
        $paragraphs = [];
        $split = preg_split('/\n{2,}/', trim($body));
        // The pattern is fixed and has no u flag, so the split never fails.
        foreach (false === $split ? [] : $split as $paragraph) {
            $parts = array_map(static fn (string $part): string => nl2br(self::escape($part), false), explode(self::LINK, $paragraph));
            $paragraphs[] = '<p>'.implode($anchor, $parts).'</p>';
        }

        return implode("\n", $paragraphs);
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_HTML5 | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
