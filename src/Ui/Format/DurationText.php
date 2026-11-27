<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Format;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFilter;

/**
 * A number of minutes on screen, such as an estimate or the time logged:
 * "2 h 30 min", "45 min", "3 h"; no minutes is "0 min". Twig's |duration.
 */
final readonly class DurationText
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    #[AsTwigFilter('duration')]
    public function shown(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        $parts = [];
        if ($hours > 0) {
            $parts[] = $this->translator->trans('ui.duration.hours', ['hours' => (string) $hours]);
        }
        if ($rest > 0 || 0 === $hours) {
            $parts[] = $this->translator->trans('ui.duration.minutes', ['minutes' => (string) $rest]);
        }

        return implode(' ', $parts);
    }
}
