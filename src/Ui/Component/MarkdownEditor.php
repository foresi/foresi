<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * A text area for Markdown, as on GitLab and GitHub: Write and Preview tabs, a small
 * formatting toolbar with Ctrl/Cmd+B, I and K, and, given "mentionsUrl", a list of
 * people after "@" (WAI-ARIA combobox with list autocomplete) that inserts the
 * mention syntax. "previewUrl" takes a POST of "text" and "_token" (the CSRF token
 * "markdown_preview") and answers the sanitised HTML. Given "uploadUrl" with the
 * kind and id of the record ("uploadKind", "uploadOwner"), an image pasted from the
 * clipboard or files dropped on the text area are uploaded there, as on GitHub, and
 * go into the text as Markdown images or links. Without JavaScript only the text
 * area shows.
 */
#[AsTwigComponent]
final class MarkdownEditor extends AbstractControl
{
    public const string TOKEN = 'markdown_preview';

    public string $value = '';
    public int $rows = 4;
    public string $previewUrl = '';
    public ?string $mentionsUrl = null;
    /** Takes a POST of "file", "owner_kind", "owner_id" and "_token" (the CSRF token "attachment_upload") and answers JSON. */
    public ?string $uploadUrl = null;
    public ?string $uploadKind = null;
    public ?string $uploadOwner = null;

    #[PostMount]
    public function validateUrls(): void
    {
        if ('' === $this->previewUrl) {
            throw new \InvalidArgumentException('A Markdown editor needs a preview URL.');
        }
        Style::href($this->previewUrl);
        Style::href($this->mentionsUrl);
        Style::href($this->uploadUrl);
        if (null !== $this->uploadUrl && (null === $this->uploadKind || null === $this->uploadOwner)) {
            throw new \InvalidArgumentException('An upload URL needs the kind and the id of the record the files go to.');
        }
    }
}
