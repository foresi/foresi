<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Pdf;

/**
 * A QR code drawn as vectors, tagged as a figure with alternative text.
 * The position is the upper left corner in millimetres. From the body, it is on the
 * first page, on a page whose body starts at the top margin: a head moves it down with
 * the body. From the foot, the block after the body carries it wherever that lands.
 */
final readonly class QrFigure
{
    public function __construct(
        public string $payload,
        public string $alt,
        public float $x,
        public float $y,
        public float $sizeMm,
        public QrAnchor $anchor = QrAnchor::Body,
    ) {
        if ('' === $payload || '' === $alt || $sizeMm <= 0.0) {
            throw new \InvalidArgumentException('A QR figure needs a payload, alternative text and a positive size.');
        }
    }
}
