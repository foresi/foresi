<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * A text area for Markdown, as on GitLab and GitHub: Write and Preview tabs, a small
 * formatting toolbar with Ctrl/Cmd+B, I and K, and, given "mentionsUrl", a list of
 * people after "@" (WAI-ARIA combobox with list autocomplete) that inserts the
 * mention syntax. "previewUrl" takes a POST of "text" and "_token" (the CSRF token
 * "markdown_preview") and answers the sanitised HTML; "previewFields" maps more
 * names to the ids of fields whose values go along, such as the subject of a mail
 * for its preview. Given "uploadUrl" with the
 * kind and id of the record ("uploadKind", "uploadOwner"), an image pasted from the
 * clipboard or files dropped on the text area are uploaded there, as on GitHub, and
 * go into the text as Markdown images or links. Given "taskUrl" (a dialog that
 * makes a task, such as a meeting's), the toolbar has Create task, also with
 * Ctrl/Cmd+Shift+K, for a selection on one line outside another task's token,
 * as Linear's create issue from selection: the dialog opens with the selection
 * as its "title", and its answer (the event markdown-editor:task-created with
 * the task's id) puts "[selection](task:id)" in place of the selection. Without
 * JavaScript only the text area shows.
 */
#[AsTwigComponent]
final class MarkdownEditor extends AbstractControl
{
    public const string TOKEN = 'markdown_preview';

    public string $value = '';
    public int $rows = 4;
    public string $previewUrl = '';
    public ?string $mentionsUrl = null;
    /**
     * Name => id of a field on the page, posted with the text to the preview.
     *
     * @var array<string, string>
     */
    public array $previewFields = [];
    /** Takes a POST of "file", "owner_kind", "owner_id" and "_token" (the CSRF token "attachment_upload") and answers JSON. */
    public ?string $uploadUrl = null;
    public ?string $uploadKind = null;
    public ?string $uploadOwner = null;
    /** A dialog that makes a task from the selection, given as "title"; without it there is no Create task. */
    public ?string $taskUrl = null;

    #[PostMount]
    public function validateUrls(): void
    {
        if ('' === $this->previewUrl) {
            throw new \InvalidArgumentException('A Markdown editor needs a preview URL.');
        }
        Style::href($this->previewUrl);
        Style::href($this->mentionsUrl);
        Style::href($this->uploadUrl);
        Style::href($this->taskUrl);
        if (null !== $this->uploadUrl && (null === $this->uploadKind || null === $this->uploadOwner)) {
            throw new \InvalidArgumentException('An upload URL needs the kind and the id of the record the files go to.');
        }
    }
}
