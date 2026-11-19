<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Shared\Application\Markdown;

/**
 * The two parts of a mail that carries a link. The body is Markdown, its values
 * already filled in as escaped text (MailText::renderBody). The HTML part is the
 * body rendered by the restricted Markdown of the installation: raw HTML stays
 * text, there are no images, links only to http, https and mailto. The plain
 * part is the source itself, which reads as text. The link goes where the body
 * has {link}, also as the target of a Markdown link such as [Open]({link}), or
 * on a paragraph of its own at the end: a link mail without its link would be
 * worthless.
 */
final readonly class LinkedMailBody
{
    private const string LINK = '{'.MailVariables::LINK.'}';
    /** {link} as the target of a Markdown link: [text]({link}). */
    private const string AS_TARGET = ']('.self::LINK.')';

    public function __construct(private string $body, private string $url, private Markdown $markdown)
    {
    }

    public function text(): string
    {
        $body = MailMarkdown::plain($this->body);
        if (str_contains($body, self::LINK)) {
            return str_replace(self::LINK, $this->url, $body);
        }

        return rtrim($body)."\n\n".$this->url;
    }

    public function html(): string
    {
        $body = str_contains($this->body, self::LINK) ? $this->body : rtrim($this->body)."\n\n".self::LINK;
        // Inside angle brackets a destination may hold anything but these.
        $url = str_replace(['<', '>', ' ', "\n", "\r", "\t"], ['%3C', '%3E', '%20', '', '', ''], $this->url);
        $body = str_replace([self::AS_TARGET, self::LINK], ['](<'.$url.'>)', '<'.$url.'>'], $body);

        return $this->markdown->toMailHtml($body);
    }
}
