<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * How far a background job has come. Without a maximum the amount of work is
 * not known yet, and the bar only says that the job is busy.
 */
#[AsTwigComponent]
final class ProgressBar
{
    public string $label = '';
    public int $value = 0;

    /** Null while the amount of work is not known. */
    public ?int $max = null;

    /** The progress in words, for example "1 500 of 3 000 rows". */
    public ?string $valueText = null;

    #[PostMount]
    public function validate(): void
    {
        if ('' === $this->label) {
            throw new \InvalidArgumentException('A progress bar needs a label.');
        }
        if (null !== $this->max && ($this->max < 0 || $this->value < 0 || $this->value > $this->max)) {
            throw new \InvalidArgumentException('A progress value must lie between 0 and the maximum.');
        }
    }

    public function determinate(): bool
    {
        return null !== $this->max;
    }

    /** The filled share in whole per cent; a job with nothing to do is done. */
    public function percent(): int
    {
        if (null === $this->max) {
            return 0;
        }

        return 0 === $this->max ? 100 : intdiv($this->value * 100, $this->max);
    }
}
