<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * Shared props and accessibility state of every labelled form control.
 *
 * An invalid control carries aria-invalid, a red border and a description
 * that points at its message. The message is the control's own "error",
 * shown under it, or "errorRef", the id of a message shown elsewhere that
 * concerns this control too (a sign-in that failed names no single field).
 * The first invalid control of a page gets the autofocus.
 */
abstract class AbstractControl
{
    public string $name = '';
    public string $id = '';
    public string $label = '';
    public ?string $help = null;
    public ?string $error = null;
    public ?string $errorRef = null;
    public bool $required = false;
    public bool $disabled = false;

    private bool $focused = false;

    public function __construct(private readonly ErrorFocus $errorFocus)
    {
    }

    #[PostMount]
    public function validateControl(): void
    {
        if ('' === $this->id) {
            $this->id = $this->name;
        }
        if ('' === $this->id) {
            throw new \InvalidArgumentException('A form control needs a name or an id.');
        }
        if ('' === $this->label) {
            throw new \InvalidArgumentException('A form control needs a label.');
        }
        $this->focused = $this->errorFocus->claim($this->invalid());
    }

    public function describedBy(): ?string
    {
        $ids = [];
        if (null !== $this->help) {
            $ids[] = $this->id.'-help';
        }
        if (null !== $this->error) {
            $ids[] = $this->id.'-error';
        }
        if (null !== $this->errorRef) {
            $ids[] = $this->errorRef;
        }

        return [] === $ids ? null : implode(' ', $ids);
    }

    public function invalid(): bool
    {
        return null !== $this->error || null !== $this->errorRef;
    }

    /** Whether this is the first invalid control of the page, which takes the focus. */
    public function focused(): bool
    {
        return $this->focused;
    }

    public function classes(): string
    {
        return 'w-full rounded-control border bg-white px-2.5 text-ink '.($this->invalid() ? 'border-badge-red-text' : 'border-line-strong').' disabled:bg-head disabled:text-muted '.Style::FOCUS;
    }
}
