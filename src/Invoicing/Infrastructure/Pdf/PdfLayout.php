<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Pdf;

/**
 * How a document sits on its pages: everything the engine takes from a theme.
 */
final readonly class PdfLayout
{
    /**
     * @param list<PdfFace> $faces        faces besides the regular and bold Inter every document has
     * @param float         $footHeightMm the height of the block after the body, which never breaks
     * @param bool          $footAtBottom the block stands on the bottom margin, not right below the body
     * @param float         $footGapMm    the least space between the body and that block
     * @param string|null   $pageFace     family of the line under each page with the document number and page; none without it
     */
    public function __construct(
        public float $marginMm = 15.0,
        public array $faces = [],
        public float $footHeightMm = 0.0,
        public bool $footAtBottom = false,
        public float $footGapMm = 0.0,
        public ?string $pageFace = null,
    ) {
    }
}
