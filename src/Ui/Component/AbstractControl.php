<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * Shared props and accessibility state of every labelled form control.
 */
abstract class AbstractControl
{
    public string $name = '';
    public string $id = '';
    public string $label = '';
    public ?string $help = null;
    public ?string $error = null;
    public bool $required = false;
    public bool $disabled = false;

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

        return [] === $ids ? null : implode(' ', $ids);
    }

    public function invalid(): bool
    {
        return null !== $this->error;
    }

    public function classes(): string
    {
        return 'w-full rounded-control border bg-white px-2.5 text-ink '.($this->invalid() ? 'border-badge-red-text' : 'border-line-strong').' disabled:bg-head disabled:text-muted '.Style::FOCUS;
    }
}
