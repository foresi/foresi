<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Contract;

use Foresi\Party\Contract\VatPeriodLength;

/**
 * One VAT period: a calendar month (key "2026-11") or a calendar quarter
 * (key "2026-Q4"), from its first day to its last, both included. The
 * country rules give the whole period; the module clips it to the payer's
 * stretch, so the first period of a payer from 12 November 2026 runs from
 * 2026-11-12 to 2026-11-30 and keeps the key "2026-11".
 */
final readonly class VatPeriod
{
    public function __construct(
        public string $key,
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
        public VatPeriodLength $length,
    ) {
    }

    /**
     * The same period from another first or last day, such as the part of it
     * in which the firm is a payer.
     */
    public function clippedTo(\DateTimeImmutable $start, \DateTimeImmutable $end): self
    {
        return new self($this->key, max($this->start, $start), min($this->end, $end), $this->length);
    }
}
