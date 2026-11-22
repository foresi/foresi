<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Application;

use Foresi\Activities\Domain\ActivityType;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The name a type shows under: the one someone gave it, or for a seeded type
 * that was never renamed, its key's name in the person's language.
 */
final readonly class ActivityTypeNames
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function of(ActivityType $type): string
    {
        return $type->name() ?? $this->translator->trans('activities.type.'.$type->key());
    }
}
