<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * A modal dialog on the native <dialog>, opened with showModal() by the dialog
 * controller: the page behind is inert, Escape closes, focus returns to what
 * opened it. Only for a short task that serves the page underneath; anything
 * longer, linkable or with its own navigation is a page. Every dialog with a
 * URL also works as that page without JavaScript.
 *
 * Sizes: sm for a confirmation or a reason, md for a short form, lg for an
 * entity form. With fields it is full-screen below 640 px and a click on the
 * backdrop does nothing; without fields the backdrop closes it. The body goes
 * in the content block, the buttons in the block "footer" (Cancel first, the
 * one primary verb last). Variants: "confirm" is an alert dialog the confirm
 * function fills, "bare" has no header and footer (the command palette). A
 * remote dialog is the one empty host that loads a dialog URL in place.
 */
#[AsTwigComponent]
final class Dialog
{
    private const array SIZES = ['sm' => 'max-w-[400px]', 'md' => 'max-w-[560px]', 'lg' => 'max-w-[720px]'];
    private const array VARIANTS = ['default', 'confirm', 'bare'];
    private const string BASE = 'm-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] flex-col overflow-hidden rounded-panel border border-container bg-white p-0 text-text shadow-overlay open:flex backdrop:bg-ink/25';
    private const string FULL_SCREEN = 'max-sm:m-0 max-sm:h-dvh max-sm:max-h-none max-sm:w-full max-sm:max-w-none max-sm:rounded-none max-sm:border-0';
    private const array ACTIONS = [
        'cancel->dialog#cancelled', 'close->dialog#closed', 'click->dialog#clicked', 'submit->dialog#submit',
        'input->dialog#changed', 'change->dialog#changed', 'turbo:before-morph-attribute->dialog#keepOpen',
        'turbo:before-cache@document->dialog#reset', 'turbo:before-render@document->dialog#reset', 'popstate@window->dialog#popped',
    ];

    public string $id;
    public ?string $title = null;
    public ?string $description = null;
    public ?string $label = null;
    public string $size = 'md';
    public string $variant = 'default';
    public bool $fields = false;
    public ?bool $dismissable = null;
    public bool $remote = false;
    /** Shows the question about unsaved changes instead of the buttons, for the catalogue. */
    public bool $asking = false;

    #[PostMount]
    public function validate(): void
    {
        if (!isset(self::SIZES[$this->size])) {
            throw new \InvalidArgumentException(sprintf('Unknown size "%s", expected one of: %s.', $this->size, implode(', ', array_keys(self::SIZES))));
        }
        if (!\in_array($this->variant, self::VARIANTS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown variant "%s", expected one of: %s.', $this->variant, implode(', ', self::VARIANTS)));
        }
        if (!$this->remote && 'bare' !== $this->variant && (null === $this->title || '' === trim($this->title))) {
            throw new \InvalidArgumentException('A dialog needs a visible title that names its task.');
        }
        if ('bare' === $this->variant && (null === $this->label || '' === trim($this->label))) {
            throw new \InvalidArgumentException('A bare dialog needs a label.');
        }
    }

    public function classes(): string
    {
        return implode(' ', array_filter([self::BASE, self::SIZES[$this->size], $this->fields ? self::FULL_SCREEN : null]));
    }

    public function actions(): string
    {
        $actions = self::ACTIONS;
        if ($this->remote) {
            $actions[] = 'click@document->dialog#follow';
        }
        if ('confirm' === $this->variant) {
            $actions[] = 'dialog:confirm@document->dialog#ask';
        }

        return implode(' ', $actions);
    }

    public function chrome(): bool
    {
        return !$this->remote && 'bare' !== $this->variant;
    }

    public function isDismissable(): bool
    {
        return $this->dismissable ?? !$this->fields;
    }
}
