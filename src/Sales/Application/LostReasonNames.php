<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application;

use Foresi\Sales\Domain\LostReason;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The name a lost reason shows under: the one someone gave it, or for a seeded
 * one that was never renamed, its key's name in the person's language.
 */
final readonly class LostReasonNames
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function of(LostReason $record): string
    {
        return $record->name() ?? $this->translator->trans('sales.lost_reason.'.$record->key());
    }
}
