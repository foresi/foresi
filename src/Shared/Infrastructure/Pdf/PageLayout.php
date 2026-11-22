<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Pdf;

/**
 * The paper and margins of a document, in millimetres. The width and height are
 * those of the sheet held upright; landscape turns it.
 */
final readonly class PageLayout
{
    /** The smallest room the margins must leave for the content, in millimetres. */
    private const float MIN_CONTENT_MM = 50.0;

    public function __construct(
        public float $widthMm,
        public float $heightMm,
        public bool $landscape,
        public float $marginTopMm,
        public float $marginRightMm,
        public float $marginBottomMm,
        public float $marginLeftMm,
    ) {
        foreach ([$marginTopMm, $marginRightMm, $marginBottomMm, $marginLeftMm] as $margin) {
            if ($margin < 0.0) {
                throw new \InvalidArgumentException('A page margin cannot be negative.');
            }
        }
        if ($this->contentWidthMm() < self::MIN_CONTENT_MM || $this->pageHeightMm() - $marginTopMm - $marginBottomMm < self::MIN_CONTENT_MM) {
            throw new \InvalidArgumentException('The page margins leave no room for the content.');
        }
    }

    /** The layout of an invoice: portrait A4 with 15 mm margins and a taller bottom one for the footer. */
    public static function invoice(): self
    {
        return new self(210.0, 297.0, false, 15.0, 15.0, 20.0, 15.0);
    }

    public function pageWidthMm(): float
    {
        return $this->landscape ? $this->heightMm : $this->widthMm;
    }

    public function pageHeightMm(): float
    {
        return $this->landscape ? $this->widthMm : $this->heightMm;
    }

    public function contentWidthMm(): float
    {
        return $this->pageWidthMm() - $this->marginLeftMm - $this->marginRightMm;
    }
}
