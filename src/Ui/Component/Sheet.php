<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * A side sheet: a panel at the right edge of the screen over the page, on the
 * native <dialog> and the same dialog controller as Ui:Dialog (layer "sheet"):
 * the page behind is inert under a light scrim, Escape, the X and a click on
 * the scrim close it, focus moves in and returns to what opened it, a changed
 * form asks before it closes, and on a phone it fills the screen and Back
 * closes it. A dialog may open over it.
 *
 * Sizes follow level.io's side panels: sm (400 px) for filters and columns,
 * md (640 px) for a detail. The detail width is resizable by a grip on the left
 * edge, from 420 px to the content panel minus 180 px, by pointer or by the
 * arrow keys in 16 px steps, and remembered per browser (layout spec, invoice
 * detail as a side sheet). The header holds the title, an optional description
 * and the X; the body alone scrolls; the footer holds the buttons.
 *
 * A remote sheet is the one empty host that loads a page as a sheet: a link
 * with data-sheet opens its URL in place, and the page extends
 * layout/sheet.html.twig for a dialog request.
 */
#[AsTwigComponent]
final class Sheet
{
    private const array SIZES = ['sm' => 'w-[400px]', 'md' => 'w-[640px]'];
    private const string BASE = 'inset-y-0 left-auto right-0 m-0 h-auto max-h-none max-w-full flex-col overflow-hidden border-0 border-l border-container bg-white p-0 text-text shadow-overlay open:flex backdrop:bg-ink/25';
    private const string FULL_SCREEN = 'max-sm:w-full max-sm:border-0';
    private const array ACTIONS = [
        'cancel->dialog#cancelled', 'close->dialog#closed', 'click->dialog#clicked', 'submit->dialog#submit',
        'input->dialog#changed', 'change->dialog#changed', 'turbo:before-morph-attribute->dialog#keepOpen',
        'turbo:before-cache@document->dialog#reset', 'turbo:before-render@document->dialog#reset', 'popstate@window->dialog#popped',
        'dialog:opened->sheet#opened', 'resize@window->sheet#fit',
    ];

    public string $id;
    public ?string $title = null;
    public ?string $description = null;
    public string $size = 'md';
    public ?bool $resizable = null;
    public bool $fields = false;
    public bool $remote = false;
    /** The URL of the record it shows (docs/design/overlays.md): adds Open as page to the header. */
    public ?string $page = null;
    /**
     * A record's title changed where it is read, as on its page (Ui:PageHeader "titleEdit").
     *
     * @var array<string, mixed>|null
     */
    public ?array $titleEdit = null;
    /** Shows the question about unsaved changes instead of the buttons, for the catalogue. */
    public bool $asking = false;

    /** @var array<string, mixed>|null */
    private ?array $titleEditView = null;

    #[PostMount]
    public function validate(): void
    {
        if (null !== $this->titleEdit) {
            $this->titleEditView = PageHeader::titleEdit($this->titleEdit, $this->title ?? '', 'The title edit of a sheet');
        }
        if (!isset(self::SIZES[$this->size])) {
            throw new \InvalidArgumentException(sprintf('Unknown size "%s", expected one of: %s.', $this->size, implode(', ', array_keys(self::SIZES))));
        }
        if (!$this->remote && (null === $this->title || '' === trim($this->title))) {
            throw new \InvalidArgumentException('A sheet needs a visible title that names what it shows.');
        }
    }

    public function classes(): string
    {
        return implode(' ', [self::BASE, self::SIZES[$this->size], self::FULL_SCREEN]);
    }

    public function actions(): string
    {
        $actions = self::ACTIONS;
        if ($this->remote) {
            $actions[] = 'click@document->dialog#follow';
        }

        return implode(' ', $actions);
    }

    /** @return array<string, mixed>|null */
    public function titleEditView(): ?array
    {
        return $this->titleEditView;
    }

    public function isResizable(): bool
    {
        return $this->resizable ?? 'md' === $this->size;
    }
}
