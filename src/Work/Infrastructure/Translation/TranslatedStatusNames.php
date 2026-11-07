<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Translation;

use App\Work\Application\StatusNames;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The names of seeded statuses from the translations, in the locale of the
 * request: the language of the signed-in person.
 */
final readonly class TranslatedStatusNames implements StatusNames
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function of(string $key): string
    {
        return $this->translator->trans($key);
    }
}
