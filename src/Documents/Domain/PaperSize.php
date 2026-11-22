<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Domain;

/**
 * The sheets a template can be printed on, held upright, in millimetres.
 */
enum PaperSize: string
{
    case A4 = 'a4';
    case Letter = 'letter';

    public function widthMm(): float
    {
        return match ($this) {
            self::A4 => 210.0,
            self::Letter => 215.9,
        };
    }

    public function heightMm(): float
    {
        return match ($this) {
            self::A4 => 297.0,
            self::Letter => 279.4,
        };
    }
}
