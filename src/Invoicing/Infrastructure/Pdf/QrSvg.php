<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Pdf;

use Com\Tecnick\Barcode\Barcode;

/**
 * A payment code as inline SVG, drawn like the PDF draws it: QR, error correction
 * M, four modules of quiet zone. Inline, so the page's policy needs no image source.
 */
final readonly class QrSvg
{
    public function svg(string $payload): string
    {
        $svg = new Barcode()->getBarcodeObj('QRCODE,M', $payload, -4, -4, 'black', [-4, -4, -4, -4])->getInlineSvgCode();
        // The payload stays out of the markup, and the size comes from the page.
        $svg = (string) preg_replace(['/<desc>.*?<\/desc>\s*/s', '/\sid="bars"/'], '', $svg);
        $svg = (string) preg_replace_callback('/^<svg\b[^>]*>/', static fn (array $tag): string => (string) preg_replace('/\s(?:width|height)="[^"]*"/', '', $tag[0]), $svg);

        return (string) preg_replace('/^<svg\b/', '<svg class="block h-auto w-full" aria-hidden="true" focusable="false"', $svg);
    }
}
