<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * Label, help and error text around a control that is passed as the content.
 *
 * A "row" puts the label to the left of the field, right-aligned in a fixed
 * label column, so every field of a form starts on one line; help and error
 * text sit under the field. Below 768 px the label stacks above the field
 * again. "width" caps the field by its expected content: short for dates and
 * numbers, medium for selects, long for names and descriptions. A "plain" row
 * holds read-only text, so its label is no <label>.
 */
#[AsTwigComponent]
final class Field
{
    public string $id = '';
    public string $label = '';
    public ?string $help = null;
    public ?string $error = null;
    public bool $required = false;
    public bool $inline = false;
    public bool $row = false;
    public bool $plain = false;
    public ?string $width = null;

    #[PostMount]
    public function validate(): void
    {
        if ('' === $this->id) {
            throw new \InvalidArgumentException('Field requires the id of its control.');
        }
        self::widthClass($this->width);
    }

    /** The cap of a field's width: short (dates, numbers), medium (selects) or long (names, text). */
    public static function widthClass(?string $width): string
    {
        return match ($width) {
            null => '',
            'short' => 'max-w-field-short',
            'medium' => 'max-w-field-medium',
            'long' => 'max-w-field-long',
            default => throw new \InvalidArgumentException(sprintf('Unknown field width "%s".', $width)),
        };
    }
}
