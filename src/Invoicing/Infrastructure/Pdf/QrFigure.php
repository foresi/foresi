<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Pdf;

/**
 * A QR code drawn as vectors, tagged as a figure with alternative text.
 * The position is the upper left corner on the first page, in millimetres, on a page
 * whose body starts at the top margin: a head moves it down with the body.
 */
final readonly class QrFigure
{
    public function __construct(
        public string $payload,
        public string $alt,
        public float $x,
        public float $y,
        public float $sizeMm,
    ) {
        if ('' === $payload || '' === $alt || $sizeMm <= 0.0) {
            throw new \InvalidArgumentException('A QR figure needs a payload, alternative text and a positive size.');
        }
    }
}
