<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Domain;

/**
 * The paper of a template version: its size, orientation and four margins in
 * millimetres (ADR 0042).
 */
final readonly class PageSettings
{
    public const float MAX_MARGIN_MM = 60.0;

    public function __construct(
        public PaperSize $paper = PaperSize::A4,
        public bool $landscape = false,
        public float $marginTopMm = 20.0,
        public float $marginRightMm = 20.0,
        public float $marginBottomMm = 20.0,
        public float $marginLeftMm = 20.0,
    ) {
        foreach (['marginTop' => $marginTopMm, 'marginRight' => $marginRightMm, 'marginBottom' => $marginBottomMm, 'marginLeft' => $marginLeftMm] as $field => $margin) {
            if ($margin < 0.0 || $margin > self::MAX_MARGIN_MM) {
                throw new InvalidField($field, \sprintf('A page margin is between 0 and %d mm.', (int) self::MAX_MARGIN_MM));
            }
        }
    }
}
